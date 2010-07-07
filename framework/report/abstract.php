<?php
/**
 * Moodlerooms Framework
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see http://opensource.org/licenses/gpl-3.0.html.
 *
 * @copyright Copyright (c) 2009 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @package mr
 * @author Mark Nielsen
 */

defined('MOODLE_INTERNAL') or die('Direct access to this script is forbidden.');

/**
 * Sam must re-implement:
 *      can_subscribe(...)
 *      iterator_init(...)
 *      load_chart(...)
 *      chart_display(...)
 *      navigation_display(...)
 *      has_report(...) - Note, may not need this anymore, IDK
 *      protected $iterator
 *
 * Removed configs:
 *      'controlpanel' => false,         // Control panel requirements, EG: array('notify', '_MR_BLOCKS')
 *      'sitereport' => true,            // Has a site level report
 *      'coursereport' => true,          // Has a course level report
 *      'coursemanagerreport' => true,   // Is a course manager report
 *      'embeddedreport' => false,       // Is an embedded report (EG: displayed in another plugin)
 *      'cansubscribe' => true,          // Users can subscribe to this report
 *      'reportsgroup' => false,         // The report group that the report belongs, EG: 'Site Engagement', 'Course Engagement'
 *
 */

/**
 * @see mr_readonly
 */
require_once($CFG->dirroot.'/local/mr/framework/readonly.php');

/**
 * @see mr_html_table
 */
require_once($CFG->dirroot.'/local/mr/framework/html/table.php');

/**
 * @see mr_html_filter
 */
require_once($CFG->dirroot.'/local/mr/framework/html/filter.php');

/**
 * @see mr_var
 */
require_once($CFG->dirroot.'/local/mr/framework/var.php');

/**
 * @see mr_preferences
 */
require_once($CFG->dirroot.'/local/mr/framework/preferences.php');

/**
 * MR Report Abstract
 *
 * @package mr
 * @author Mark Nielsen
 */
abstract class mr_report_abstract extends mr_readonly implements renderable {
    /**
     * Table model
     *
     * @var mr_html_table
     */
    protected $table;

    /**
     * Filter model
     *
     * @var mr_html_filter
     */
    protected $filter;

    /**
     * User preferences
     *
     * @var mr_preferences
     */
    protected $preferences;

    /**
     * Config Model
     *
     * @var mr_var
     */
    protected $config;

    /**
     * Base URL
     *
     * @var moodle_url
     */
    protected $url;

    /**
     * Course ID
     *
     * @var int
     */
    protected $courseid;

    /**
     * Export plugin
     *
     * @var mr_file_export_abstract
     */
    protected $export;

    /**
     * SQL generated by this report
     *
     * @var string
     */
    protected $sql = '';

    /**
     * Global AJAX default config
     *
     * @var int
     */
    static public $ajaxdefault = NULL;

    /**
     * Construct
     *
     * @param moodle_url $url Base URL
     * @param int $courseid Course ID
     */
    public function __construct($url = NULL, $courseid = NULL) {
        global $CFG;

        if (is_null($courseid) or $courseid == 0) {
            $courseid = SITEID;
        }

        $this->url         = $url;
        $this->courseid    = $courseid;
        $this->config      = new mr_var();
        $this->preferences = new mr_preferences($courseid, $this->type());

        // @todo what to do with this?
        if (($setajax = optional_param('setajax', -1, PARAM_INT)) != -1) {
            $this->preferences->set('ajax', $setajax);
        }

        // Setup config defaults
        $this->config->set(array(
            'cache' => false,                // Enable report caching
            'ajax' => false,                 // Allow AJAX table view
            'export' => false,               // Export options, an array of export formats or true for all
            'maxrows' => 65000,              // The maximum number of rows the report can report on
            'perpage' => false,              // Can the page size be changed?
            'perpageopts' => array(          // Page size options
                'all', 10, 25, 50, 100, 200, 500, 1000,
            ),
        ));
        $this->_init();
    }

    /**
     * Convert this report into a simple string
     *
     * @return string
     */
    public function __toString() {
        global $USER;

        $report = $this->type();

        return "user{$USER->id}course{$this->courseid}report{$report}$this->table$this->filter";
    }

    protected function _init() {
        $this->init();
        $this->table_init();
        $this->filter_init();

        // Override settings based on other global settings
        $this->config->ajax = ($this->config->ajax and ajaxenabled());

        // Setup Paging
        $this->paging = new mr_html_paging($this->preferences, $this->url);
        // $this->paging->set_total($DB->count_records('user'));
        if ($this->config->perpage) {
            $this->paging->set_perpageopts($this->config->perpageopts);
        }

        // Setup Export
        if ($this->config->export) {
            $this->export = new mr_file_export($this->config->export, false, $this->url, $this->name());
        }
    }

    /**
     * Set report specific configs
     *
     * @return void
     */
    public function init() {
    }

    /**
     * Filter setup - override to add a filter
     *
     * @return void
     */
    public function filter_init() {
    }

    /**
     * Table setup
     *
     * Override and set $this->table to an instance of
     * mr_html_table.
     *
     * @return void
     */
    abstract public function table_init();

    /**
     * Passed to get_string calls.
     *
     * @return string
     */
    abstract public function get_component();

    /**
     * Return a human readable name of the plugin
     *
     * @return string
     */
    public function name() {
        return get_string($this->type(), $this->get_component());
    }

    /**
     * Returns the plugin's name based on class name
     *
     * @return string
     */
    public function type() {
        return get_class($this);
    }

    /**
     * Get report description text
     *
     * @return mixed
     */
    public function get_description() {
        $identifier  = $this->type().'_description';
        if (get_string_manager()->string_exists($identifier, $this->get_component())) {
            return get_string($identifier, $this->get_component());
        }
        return false;
    }

    /**
     * Generate JSON data for this report
     *
     * @return string
     */
    public function json() {
        if ($this->config->ajax and $this->preferences->get('ajax', self::$ajaxdefault)) {
            // Fill the table
            $this->table_fill();

            if (!$this->table instanceof mr_html_table_ajax) {
                throw new coding_exception('Invalid table model');
            }

            return $this->table->json();
        }
        return '';
    }

    /**
     * YUI inline cell editing - this gets called to save
     * the edited data.
     *
     * Also perform any additional capability checks in this method!
     *
     * @param object $row Table row data - THIS MUST BE CLEANED BEFORE USE!
     * @param string $column The column that was edited
     * @param string $value The new column value, THIS MUST BE CLEANED BEFORE SAVING!
     * @return mixed Return false on error, return value saved to DB on success,
     *               or return a JSON object (see editcell_action in default controller)
     */
    public function save_cell($row, $column, $value) {
        return false;
    }

    /**
     * Export report
     *
     * Example Code:
     * <?php
     *      $report = new some_report_class(...);
     *      $file   = $report->export('text/csv');
     *
     *      // Do something with $file, then to delete it...
     *      $report->get_export()->cleanup();
     * ?>
     *
     * @param string $exporter The exporter to use, like 'text/csv'
     * @param string $filename Override the file name
     * @return string The file path
     */
    public function export($exporter, $filename = NULL) {
        // Set the exporter
        $this->export->init($exporter, $filename);

        $this->filter_init();
        $this->table_init();

        // Setup table for export, send all records to export plugin
        $this->table->set_export($this->export);
        $this->paging->set_export($this->export);

        // Send rows to export
        $this->table_fill();

        // Return the file
        return $this->export->close();
    }

    /**
     * Generate SQL from filter
     *
     * @return string
     */
    public function filter_sql() {
        if ($this->filter instanceof mr_html_filter) {
            return $this->filter->sql();
        }
        return '';
    }

    /**
     * Fill table with data
     *
     * @return void
     */
    public function table_fill() {
        // Best place to do this?
        if ($this->export instanceof mr_file_export and $this->export->is_exporting()) {
            $this->table->set_export($this->export);
            $this->paging->set_export($this->export);
        }

        // if (!$this->config->cache or !$this->table->cached()) {
            $total = $this->get_recordset_count($this->filter_sql());

            if ($this->config->maxrows == 0 or $total <= $this->config->maxrows) {
                $rs = $this->get_recordset(
                    $this->filter_sql(),
                    $this->get_sql_sort(),
                    $this->paging->get_limitfrom(),
                    $this->paging->get_limitnum()
                );
                foreach ($rs as $row) {
                    $this->table_fill_row($row);
                }
                $rs->close();

                if ($this->paging->get_perpage() > 0) {
                    $this->paging->set_total($total);
                }
            } else {
                $this->table->set_emptymessage(
                    get_string('toomanyrows', 'local_mr', (object) array('total' => $total, 'max' => $this->config->maxrows))
                );
            }
        // }

        // Best place to do this?
        // if ($this->export instanceof mr_file_export) {
        //     $this->export->send();
        // }
    }

    /**
     * Add a row to the table
     *
     * @param mixed $row The row to add
     * @return void
     */
    public function table_fill_row($row) {
        $this->table->add_row($row);
    }

    /**
     * Get the recordset to the data for the report
     *
     * @param string $filter Filter SQL
     * @param string $sort Sort SQL
     * @param string $limitfrom Limit from SQL
     * @param string $limitnum Limit number SQL
     * @return recordset
     */
    public function get_recordset($filter = '', $sort = '', $limitfrom = '', $limitnum = '') {
        global $DB;

        if (!$sqlbody = $this->get_sql_body() or !$sqlselect = $this->get_sql_select()) {
            return false;
        }
        if (!empty($sort)) {
            $sort = "ORDER BY $sort";
        }
        $sqlgroupby = $this->get_sql_groupby();
        $sql        = "SELECT $sqlselect\n$sqlbody\n$filter\n$sqlgroupby\n$sort";
        $this->sql  = "$sql\nlimit $limitfrom, $limitnum";

        return $DB->get_recordset_sql($sql, NULL, $limitfrom, $limitnum);
    }

    /**
     * Count the total number of records
     * that are included in the report
     *
     * @param string $filter Filter SQL
     * @return int
     */
    public function get_recordset_count($filter = '') {
        global $DB;

        if (!$sqlbody = $this->get_sql_body()) {
            return 0;
        }
        $sqlgroupby = $this->get_sql_groupby();

        if (empty($sqlgroupby)) {
            $sql = "SELECT COUNT(*) $sqlbody$filter$sqlgroupby";
        } else {
            $sql = "SELECT COUNT(*) FROM (SELECT COUNT(*) $sqlbody$filter$sqlgroupby) t";
        }
        return $DB->count_records_sql($sql);
    }

    /**
     * Define the SQL select fields for the report
     *
     * @return string
     */
    public function get_sql_select() {
        return $this->table->get_sql_select();
    }

    /**
     * Define the body of the SQL query, start with FROM
     *
     * @return string
     */
    public function get_sql_body() {
        return '';
    }

    /**
     * If groupby is needed, override this method.
     * Do not add groupby to your get_sql_body()
     *
     * Return a string like: ' GROUP BY fieldname'
     *
     * @return string
     */
    public function get_sql_groupby() {
        return '';
    }

    /**
     * Get sorting SQL
     *
     * @return string
     */
    public function get_sql_sort() {
        return $this->table->get_sql_sort();
    }
}