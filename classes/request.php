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
 * Request class
 *
 * @package    local_extension
 * @author     Nicholas Hoobin <nicholashoobin@catalyst-au.net>
 * @copyright  Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_extension;

/**
 * Request class.
 *
 * @package    local_extension
 * @author     Nicholas Hoobin <nicholashoobin@catalyst-au.net>
 * @copyright  Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class request implements \cache_data_source {

    /** @var integer The local_extension_request database object */
    public $requestid = null;

    /** @var stdClass The local_extension_request database object */
    public $request = array();

    /** @var cm[] cm */
    public $cms = array();

    /** @var array An array of comment objects from the request id */
    public $comments = array();

    /** @var array An array of user objects with the available fields user_picture::fields  */
    public $users = array();

    /** @var array An array of attached files that exist for this request id */
    public $files = array();

    /** @var array An array of mods that are used */
    public $mods = array();

    /** @var array An array of roles that map to cm items and users */
    public $roles = array();

    /**
     * Request object constructor.
     * @param integer $requestid An optional variable to identify the request.
     */
    public function __construct($requestid = null) {
        $this->requestid = $requestid;
    }

    /**
     * Loads data into the object
     * @throws coding_exception
     */
    public function load() {
        global $DB;

        if (empty($this->requestid)) {
            throw \coding_exception('No request id');
        }

        $requestid = $this->requestid;

        $this->request  = $DB->get_record('local_extension_request', array('id' => $requestid), '*', MUST_EXIST);
        $this->comments = $DB->get_records('local_extension_comment', array('request' => $requestid), 'timestamp ASC');

        $request = $this->request;

        $options = array(
            'requestid' => $requestid
        );

        list($handlers, $mods) = \local_extension\utility::get_activities($request->userid, $request->searchstart, $request->searchend, $options);
        $this->mods = $mods;

        foreach ($mods as $id => $mod) {
            $cm = $mod['localcm'];
            $this->cms[$cm->cmid] = $cm;
        }

        $userids = array($request->userid => $request->userid);

        // TODO need to sort cms by date and comments by date.

        // Obtain a unique list of userids that have been commenting.
        foreach ($this->comments as $comment) {
            $userids[$comment->userid] = $comment->userid;
        }

        // Add to the $userids, a list of users that are subscribed to this request.
        $subscribedids = $DB->get_fieldset_select('local_extension_subscription', 'userid', 'requestid = :requestid', array('requestid' => $this->requestid));
        foreach ($subscribedids as $key => $userid) {
            $userids[$userid] = $userid;
        }

        // Fetch the users.
        list($uids, $params) = $DB->get_in_or_equal($userids);
        $userfields = \user_picture::fields();
        $sql = "SELECT $userfields
                  FROM {user}
                 WHERE id $uids";
        $this->users = $DB->get_records_sql($sql, $params);
    }

    /**
     * Obtain a request class with the given id.
     *
     * @param integer $requestid An id for a request.
     * @return request $req A request data object.
     */
    public static function from_id($requestid) {
        $request = new request($requestid);
        $request->load();
        return $request;
    }

    /**
     * Fetches the attachments for this request.
     *
     * @return file_storage[]|stored_file[][]
     */
    public function fetch_attachments() {
        $request = $this->request;

        // Obtain the context for the user that has submitted a request.
        $usercontext = \context_user::instance($request->userid);

        $fs = get_file_storage();

        $files = $fs->get_area_files($usercontext->id, 'local_extension', 'attachments', $request->id);

        return array($fs, $files);
    }

    /**
     * Adds a comment to the request
     *
     * @param stdClass $from The user that has commented.
     * @param string $comment The comment itself.
     */
    public function add_comment($from, $comment) {
        global $DB;

        $comment = (object)array(
            'request'       => $this->requestid,
            'userid'        => $from->id,
            'timestamp'     => \time(),
            'message'       => $comment,
        );
        $DB->insert_record('local_extension_comment', $comment);

        // Invalidate the cache for this request. The content has changed.
        self::get_data_cache()->delete($this->requestid);
    }

    /**
     * Loads comments from the database into the request object.
     */
    public function load_comments() {
        global $DB;

        if (!empty($this->requestid)) {
            $this->comments = $DB->get_records('local_extension_comment', array('request' => $this->requestid), 'timestamp ASC');
        }
    }

    /**
     * Returns the state change history to be used with interleaving the comment stream when viewing status.php
     *
     * @return array $history
     */
    public function state_history() {
        global $DB;

        // Selecting the state changes from the history for this request.
        $sql = "SELECT id,
               localcmid,
               requestid,
               timestamp,
               state,
               userid
          FROM {local_extension_his_state}
         WHERE requestid = :requestid";

        $records = $DB->get_records_sql($sql, array('requestid' => $this->requestid));
        $history = array();

        foreach ($records as $record) {
            $mod = $this->mods[$record->localcmid];

            /* @var \local_extension\cm $localcm */
            $localcm = $mod['localcm'];
            $event   = $mod['event'];
            $course  = $mod['course'];

            $status = $localcm->get_state_name($record->state);
            $log = "$status extension for {$course->fullname}, {$event->name}";

            // Add class property 'message' to interleave with the comment stream.
            $record->message = $log;

            $history[] = $record;
        }

        return $history;
    }

    /**
     * Each cm may have a different set of rules that will need to be processed.
     */
    public function process_triggers() {

        // There can only be one request per course module.
        foreach ($this->mods as $mod) {
            $handler = $mod['handler'];

            // Rules are saved in the handler as a static value to prevent duplicate lookups.
            $allrules = $handler->get_triggers();

            // Filter the rules for only this handler type.
            $rules = array_filter($allrules, function($obj) use ($handler) {
                if ($obj->datatype == $handler->get_data_type()) {
                    return $obj;
                }
            });

            $ordered = \local_extension\utility::rule_tree($rules);

            foreach ($ordered as $rule) {
                $this->process_recursive($mod, $rule);
            }

        }

        // Invalidate the cache for this request, there may be new users subscribed.
        $this->get_data_cache()->delete($this->requestid);
    }

    private function process_recursive($mod, $rule) {
        /* @var \local_extension\rule $rule */
        $rule->process($this->request, $mod);

        if (!empty($rule->children)) {

            foreach ($rule->children as $child) {
                $this->process_recursive($mod, $child);
            }

        }

    }

    /**
     * Iterates over every localcm and checks the status. If they are all cancelled then this will return false.
     *
     * @return boolean
     */
    public function check_active() {
        foreach ($cms as $cm) {
            /* @var $cm cm */
            $state = $cm->get_stateid();

            if ($state != cm::STATE_CANCEL) {
                return true;
            }
        }

        return false;
    }

    /**
     * Updates the cm state with via posted data.
     *
     * @param integer $user
     * @param stdClass $data
     */
    public function update_cm_state($user, $data) {

        foreach ($this->mods as $id => $mod) {
            $handler = $mod['handler'];

            /* @var \local_extension\cm $localcm */
            $localcm = $mod['localcm'];
            $event   = $mod['event'];
            $course  = $mod['course'];

            $statearray = array(
                'approve' => \local_extension\cm::STATE_APPROVED,
                'deny' => \local_extension\cm::STATE_DENIED,
                'cancel' => \local_extension\cm::STATE_CANCEL,
                'reopen' => \local_extension\cm::STATE_REOPENED,
            );

            foreach ($statearray as $name => $state) {
                $item = $name . $id;

                if (!empty($data->$item)) {
                    $localcm->set_state($state);
                    $status = $localcm->get_state_name();
                    $localcm->write_history($mod, $state, $user->id);
                }

            }

        }

    }

    /**
     * Returns the request cache.
     *
     * @return cache_application|cache_session|cache_store
     */
    public function get_data_cache() {
        return \cache::make('local_extension', 'requests');
    }

    /**
     * See cache_data_source::load_for_cache.
     *
     * {@inheritDoc}
     * @see cache_data_source::load_for_cache()
     *
     * @param integer $requestid
     * @return \local_extension\request
     */
    public function load_for_cache($requestid) {
        return self::from_id($requestid);
    }

    /**
     * See cache_data_source::load_many_for_cache.
     *
     * {@inheritDoc}
     * @see cache_data_source::load_many_for_cache()
     *
     * @param array $requestids
     * @return \local_extension\request[]
     */
    public function load_many_for_cache(array $requestids) {
        $requests = array();

        foreach ($requestids as $requestid) {
            $requests[$requestid] = self::from_id($requestid);
        }

        return $requests;
    }

    /**
     * See cache_data_source::get_instance_for_cache.
     *
     * @param \cache_definition $definition
     * @return \local_extension\request
     */
    public static function get_instance_for_cache(\cache_definition $definition) {
        return new request();
    }

}
