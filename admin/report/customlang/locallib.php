<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Definition of classes used by Langugae customization admin report
 *
 * @package    report
 * @subpackage customlang
 * @copyright  2010 David Mudrak <david@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Provides various utilities to be used by the plugin
 *
 * All the public methods here are static ones, this class can not be instantiated
 */
class report_customlang_utils {

    /** @var array cache of {@link self::list_components()} results */
    protected static $components = null;

    /**
     * This class can not be instantiated
     */
    private function __construct() {
    }

    /**
     * Returns a list of all components installed on the server
     *
     * @return array (string)legacyname => (string)frankenstylename
     */
    public static function list_components() {

        $list['moodle'] = 'core';

        $coresubsystems = get_core_subsystems();
        ksort($coresubsystems); // should be but just in case
        foreach ($coresubsystems as $name => $location) {
            if ($name != 'moodle.org') {
                $list[$name] = 'core_'.$name;
            }
        }

        $plugintypes = get_plugin_types();
        foreach ($plugintypes as $type => $location) {
            $pluginlist = get_plugin_list($type);
            foreach ($pluginlist as $name => $ununsed) {
                if ($type == 'mod') {
                    if (array_key_exists($name, $list)) {
                        throw new Exception('Activity module and core subsystem name collision');
                    }
                    $list[$name] = $type.'_'.$name;
                } else {
                    $list[$type.'_'.$name] = $type.'_'.$name;
                }
            }
        }

        return $list;
    }

    /**
     * Updates the translator database with the strings from files
     *
     * This should be executed each time before going to the translation page
     *
     * @param string $lang language code to checkout
     */
    public static function checkout($lang) {
        global $DB;

        // make sure that all components are registered
        $current = $DB->get_records('customlang_components', null, 'name', 'name,version,id');
        foreach (self::list_components() as $component) {
            if (empty($current[$component])) {
                $record = new stdclass();
                $record->name = $component;
                if (!$version = get_component_version($component)) {
                    $record->version = null;
                } else {
                    $record->version = $version;
                }
                $DB->insert_record('customlang_components', $record);
            } elseif ($version = get_component_version($component)) {
                if (is_null($current[$component]->version) or ($version > $current[$component]->version)) {
                    $DB->set_field('customlang_components', 'version', $version, array('id' => $current[$component]->id));
                }
            }
        }
        unset($current);

        // reload components and fetch their strings
        $stringman  = get_string_manager();
        $components = $DB->get_records('customlang_components');
        foreach ($components as $component) {
            $sql = "SELECT stringid, s.*
                      FROM {customlang} s
                     WHERE lang = ? AND componentid = ?
                  ORDER BY stringid";
            $current = $DB->get_records_sql($sql, array($lang, $component->id));
            $english = $stringman->load_component_strings($component->name, 'en', true, true);
            if ($lang == 'en') {
                $master =& $english;
            } else {
                $master = $stringman->load_component_strings($component->name, $lang, true, true);
            }
            $local = $stringman->load_component_strings($component->name, $lang, true, false);

            foreach ($english as $stringid => $stringoriginal) {
                $stringmaster = isset($master[$stringid]) ? $master[$stringid] : null;
                $stringlocal = isset($local[$stringid]) ? $local[$stringid] : null;
                $now = time();

                if (isset($current[$stringid])) {
                    $needsupdate     = false;
                    $currentoriginal = $current[$stringid]->original;
                    $currentmaster   = $current[$stringid]->master;
                    $currentlocal    = $current[$stringid]->local;

                    if ($currentoriginal !== $stringoriginal or $currentmaster !== $stringmaster) {
                        $needsupdate = true;
                        $current[$stringid]->original       = $stringoriginal;
                        $current[$stringid]->master         = $stringmaster;
                        $current[$stringid]->timemodified   = $now;
                        $current[$stringid]->outdated       = 1;
                    }

                    if ($stringmaster !== $stringlocal) {
                        $needsupdate = true;
                        $current[$stringid]->local          = $stringlocal;
                        $current[$stringid]->timecustomized = $now;
                    }

                    if ($needsupdate) {
                        $DB->update_record('customlang', $current[$stringid]);
                        continue;
                    }

                } else {
                    $record                 = new stdclass();
                    $record->lang           = $lang;
                    $record->componentid    = $component->id;
                    $record->stringid       = $stringid;
                    $record->original       = $stringoriginal;
                    $record->master         = $stringmaster;
                    $record->timemodified   = $now;
                    $record->outdated       = 0;
                    if ($stringmaster !== $stringlocal) {
                        $record->local          = $stringlocal;
                        $record->timecustomized = $now;
                    } else {
                        $record->local          = null;
                        $record->timecustomized = null;
                    }

                    $DB->insert_record('customlang', $record);
                }
            }
        }
    }

    /**
     * Exports the translator database into disk files
     *
     * @param mixed $lang language code
     */
    public static function checkin($lang) {
        global $DB, $USER, $CFG;
        require_once($CFG->libdir.'/filelib.php');

        if ($lang !== clean_param($lang, PARAM_LANG)) {
            return false;
        }

        // get all customized strings from updated components
        $sql = "SELECT s.*, c.name AS component
                  FROM {customlang} s
                  JOIN {customlang_components} c ON s.componentid = c.id
                 WHERE s.lang = ?
                       AND (s.local IS NOT NULL OR s.modified = 1)
              ORDER BY componentid, stringid";
        $strings = $DB->get_records_sql($sql, array($lang));

        $files = array();
        foreach ($strings as $string) {
            if (!is_null($string->local)) {
                $files[$string->component][$string->stringid] = $string->local;
            }
        }

        fulldelete(self::get_localpack_location($lang));
        foreach ($files as $component => $strings) {
            self::dump_strings($lang, $component, $strings);
        }

        $DB->set_field_select('customlang', 'modified', 0, 'lang = ?', array($lang));
        $sm = get_string_manager();
        $sm->reset_caches();
    }

    /**
     * Returns full path to the directory where local packs are dumped into
     *
     * @param string $lang language code
     * @return string full path
     */
    protected static function get_localpack_location($lang) {
        global $CFG;

        return $CFG->langlocalroot.'/'.$lang.'_local';
    }

    /**
     * Writes strings into a local language pack file
     *
     * @param string $component the name of the component
     * @param array $strings
     */
    protected static function dump_strings($lang, $component, $strings) {
        global $CFG;

        if ($lang !== clean_param($lang, PARAM_LANG)) {
            debugging('Unable to dump local strings for non-installed language pack .'.s($lang));
            return false;
        }
        if ($component !== clean_param($component, PARAM_SAFEDIR)) {
            throw new coding_exception('Incorrect component name');
        }
        if (!$filename = self::get_component_filename($component)) {
            debugging('Unable to find the filename for the component '.s($component));
            return false;
        }
        if ($filename !== clean_param($filename, PARAM_FILE)) {
            throw new coding_exception('Incorrect file name '.s($filename));
        }
        list($package, $subpackage) = normalize_component($component);
        $packageinfo = " * @package    $package";
        if (!is_null($subpackage)) {
            $packageinfo .= "\n * @subpackage $subpackage";
        }
        $filepath = self::get_localpack_location($lang);
        if ($filepath !== clean_param($filepath, PARAM_SAFEPATH)) {
            throw new coding_exception('Incorrect file location '.s($filepath));
        }
        $filepath = $filepath.'/'.$filename;
        if (!is_dir(dirname($filepath))) {
            mkdir(dirname($filepath), 0755, true);
        }

        if (!$f = fopen($filepath, 'w')) {
            debugging('Unable to write '.s($filepath));
            return false;
        }
        fwrite($f, <<<EOF
<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Local language pack from $CFG->wwwroot
 *
$packageinfo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();


EOF
        );

        foreach ($strings as $stringid => $text) {
            if ($stringid !== clean_param($stringid, PARAM_STRINGID)) {
                debugging('Invalid string identifier '.s($stringid));
                continue;
            }
            fwrite($f, '$string[\'' . $stringid . '\'] = ');
            fwrite($f, var_export($text, true));
            fwrite($f, ";\n");
        }
        fclose($f);
    }

    /**
     * Returns the name of the file where the component's local strings should be exported into
     *
     * @param string $component normalized name of the component, eg 'core' or 'mod_workshop'
     * @return string|boolean filename eg 'moodle.php' or 'workshop.php', false if not found
     */
    protected static function get_component_filename($component) {
        if (is_null(self::$components)) {
            self::$components = self::list_components();
        }
        $return = false;
        foreach (self::$components as $legacy => $normalized) {
            if ($component === $normalized) {
                $return = $legacy.'.php';
                break;
            }
        }
        return $return;
    }

    /**
     * Returns the number of modified strings checked out in the translator
     *
     * @param string $lang language code
     * @return int
     */
    public static function get_count_of_modified($lang) {
        global $DB;

        return $DB->count_records('customlang', array('lang'=>$lang, 'modified'=>1));
    }

    /**
     * Saves filter data into a persistant storage such as user session
     *
     * @see self::load_filter()
     * @param stdclass $data filter values
     * @param stdclass $persistant storage object
     */
    public static function save_filter(stdclass $data, stdclass $persistant) {
        if (!isset($persistant->report_customlang_filter)) {
            $persistant->report_customlang_filter = array();
        }
        foreach ($data as $key => $value) {
            if ($key !== 'submit') {
                $persistant->report_customlang_filter[$key] = serialize($value);
            }
        }
    }

    /**
     * Loads the previously saved filter settings from a persistent storage
     *
     * @see self::save_filter()
     * @param stdclass $persistant storage object
     * @return stdclass filter data
     */
    public static function load_filter(stdclass $persistant) {
        $data = new stdclass();
        if (isset($persistant->report_customlang_filter)) {
            foreach ($persistant->report_customlang_filter as $key => $value) {
                $data->{$key} = unserialize($value);
            }
        }
        return $data;
    }
}

/**
 * Represents the action menu of the report
 */
class report_customlang_menu implements renderable {

    /** @var menu items */
    protected $items = array();

    public function __construct(array $items = array()) {
        global $CFG;

        foreach ($items as $itemkey => $item) {
            $this->add_item($itemkey, $item['title'], $item['url'], empty($item['method']) ? 'post' : $item['method']);
        }
    }

    /**
     * Returns the menu items
     *
     * @return array (string)key => (object)[->(string)title ->(moodl_url)url ->(string)method]
     */
    public function get_items() {
        return $this->items;
    }

    /**
     * Adds item into the menu
     *
     * @param string $key item identifier
     * @param string $title localized action title
     * @param moodle_url $url action handler
     * @param string $method form method
     */
    public function add_item($key, $title, moodle_url $url, $method) {
        if (isset($this->items[$key])) {
            throw new coding_error('Menu item already exists');
        }
        if (empty($title) or empty($key)) {
            throw new coding_error('Empty title or item key not allowed');
        }
        $item = new stdclass();
        $item->title = $title;
        $item->url = $url;
        $item->method = $method;
        $this->items[$key] = $item;
    }
}

/**
 * Represents the translation tool
 */
class report_customlang_translator implements renderable {

    /** @const int number of rows per page */
    const PERPAGE = 100;

    /** @var int total number of the rows int the table */
    public $numofrows = 0;

    /** @var moodle_url */
    public $handler;

    /** @var string language code */
    public $lang;

    /** @var int page to display, starting with page 0 */
    public $currentpage = 0;

    /** @var array of stdclass strings to display */
    public $strings = array();

    /** @var stdclass */
    protected $filter;

    public function __construct(moodle_url $handler, $lang, $filter, $currentpage = 0) {
        global $DB;

        $this->handler      = $handler;
        $this->lang         = $lang;
        $this->filter       = $filter;
        $this->currentpage  = $currentpage;

        if (empty($filter) or empty($filter->component)) {
            // nothing to do
            $this->currentpage = 1;
            return;
        }

        list($insql, $inparams) = $DB->get_in_or_equal($filter->component, SQL_PARAMS_NAMED);

        $csql = "SELECT COUNT(*)";
        $fsql = "SELECT s.id, s.*, c.name AS component";
        $sql  = "  FROM {customlang_components} c
                   JOIN {customlang} s ON s.componentid = c.id
                  WHERE s.lang = :lang
                        AND c.name $insql";

        $params = array_merge(array('lang' => $lang), $inparams);

        if (!empty($filter->customized)) {
            $sql .= "   AND s.local IS NOT NULL";
        }

        if (!empty($filter->modified)) {
            $sql .= "   AND s.modified = 1";
        }

        if (!empty($filter->stringid)) {
            $sql .= "   AND s.stringid = :stringid";
            $params['stringid'] = $filter->stringid;
        }

        if (!empty($filter->substring)) {
            $sql .= "   AND (s.original ".$DB->sql_ilike()." :substringoriginal OR
                             s.master ".$DB->sql_ilike()." :substringmaster OR
                             s.local ".$DB->sql_ilike()." :substringlocal)";
            $params['substringoriginal'] = '%'.$filter->substring.'%';
            $params['substringmaster']   = '%'.$filter->substring.'%';
            $params['substringlocal']    = '%'.$filter->substring.'%';
        }

        if (!empty($filter->helps)) {
            $sql .= "   AND s.stringid ".$DB->sql_ilike()." '%\\\\_help'";
        } else {
            $sql .= "   AND s.stringid NOT ".$DB->sql_ilike()." '%\\\\_link'";
        }

        $osql = " ORDER BY c.name, s.stringid";

        $this->numofrows = $DB->count_records_sql($csql.$sql, $params);
        $this->strings = $DB->get_records_sql($fsql.$sql.$osql, $params, ($this->currentpage) * self::PERPAGE, self::PERPAGE);
    }
}