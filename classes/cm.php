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
 * Course module class.
 *
 * @package    local_extension
 * @author     Nicholas Hoobin <nicholashoobin@catalyst-au.net>
 * @copyright  Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_extension;

/**
 * Course module class.
 *
 * @package    local_extension
 * @author     Nicholas Hoobin <nicholashoobin@catalyst-au.net>
 * @copyright  Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cm {
    /** @var integer New request. */
    const STATE_NEW = 0;

    /** @var integer Denied request. */
    const STATE_DENIED = 1;

    /** @var integer Approved request. */
    const STATE_APPROVED = 2;

    /** @var integer Reopened request. */
    const STATE_REOPENED = 4;

    /** @var integer Cancelled request. */
    const STATE_CANCEL = 8;

    /** @var integer The local_extension_cm id */
    public $cmid = null;

    /** @var integer The user id assocaited with this cm */
    public $userid = null;

    /** @var integer The request id associated with this cm */
    public $requestid = null;

    /** @var stdClass The local_extension_cm database object */
    public $cm = null;

    /**
     * Cm constructor
     *
     * @param integer $cmid
     * @param integer $userid
     * @param integer $requestid
     */
    public function __construct($cmid, $userid, $requestid) {
        $this->cmid = $cmid;
        $this->userid = $userid;
        $this->requestid = $requestid;
    }

    /**
     * Obtain a cm class with the requestid.
     *
     * @param integer $cmid
     * @param integer $requestid
     * @return request $req A request data object.
     */
    public static function from_requestid($cmid, $requestid) {
        global $DB;

        $cm = new cm($cmid, null, $requestid);

        $conditions = array('cmid' => $cm->cmid, 'request' => $cm->requestid);
        $record = $DB->get_record('local_extension_cm', $conditions, 'cmid,course,data,id,request,state,userid');

        if (!empty($record)) {
            $cm->userid = $record->userid;
            $cm->cm = $record;
        }

        return $cm;
    }

    /**
     * Obtain a cm class with the userid.
     *
     * @param integer $cmid
     * @param integer $userid
     * @return request $req A request data object.
     */
    public static function from_userid($cmid, $userid) {
        global $DB;

        $localcm = new cm($cmid, $userid, null);

        $conditions = array('cmid' => $localcm->cmid, 'userid' => $localcm->userid);
        $cm = $DB->get_record('local_extension_cm', $conditions, 'cmid,course,data,id,request,state,userid');

        if (!empty($cm)) {
            $localcm->cm = $cm;
            $localcm->requestid = $cm->request;
        }

        return $localcm;
    }

    /**
     * Parses submitted form data and sets the properties of this class to match.
     *
     * @param stdClass $form
     */
    public function load_from_form($form) {

        foreach ($form as $key => $value) {

            if (property_exists($this, $key)) {
                $this->$key = $form->$key;

            } else {
                if ($key == 'datatype') {
                    $this->cm->data['datatype'] = $form->$key;
                }
            }

        }

        $this->data_save();
    }

    /**
     * Unserialises and base64_decodes the saved custom data.
     * @return data
     */
    public function data_load() {
        return unserialize(base64_decode($this->get_data()));
    }

    /**
     * Saves the custom data, serialising it and then base64_encoding.
     */
    public function data_save() {
        $data = base64_encode(serialize($this->get_data()));
        $this->set_data($data);
    }

    /**
     * Sets the state of this request.
     *
     * @param integer $state
     */
    public function set_state($state) {
        global $DB;

        $this->set_stateid($state);
        $DB->update_record('local_extension_cm', $this->cm);

        \local_extension\utility::cache_invalidate_request($this->requestid);
    }

    /**
     * Writes an state change entry to local_extension_his_state. Returns the history object.
     *
     * @param stdClass $mod
     * @param integer $state
     * @param integer $userid
     * @return stdClass $history
     */
    public function write_history($mod, $state, $userid) {
        global $DB;

        $localcm = $mod['localcm'];

        $history = array(
            'localcmid' => $localcm->cmid,
            'requestid' => $localcm->requestid,
            'timestamp' => time(),
            'state' => $state,
            'userid' => $userid,
        );

        $DB->insert_record('local_extension_his_state', $history);

        return $history;
    }

    /**
     * Returns a human readable state name.
     *
     * @param integer $stateid State id.
     * @throws coding_exception
     * @return string the human-readable status name.
     */
    public function get_state_name($stateid = null) {
        if ($stateid == null) {
            $stateid = $this->get_stateid();
        }
        switch ($stateid) {
            case self::STATE_NEW:
                return \get_string('state_new',      'local_extension');
            case self::STATE_DENIED:
                return \get_string('state_denied',   'local_extension');
            case self::STATE_APPROVED:
                return \get_string('state_approved', 'local_extension');
            case self::STATE_REOPENED:
                return \get_string('state_reopened', 'local_extension');
            case self::STATE_CANCEL:
                return \get_string('state_cancel',   'local_extension');
            default:
                throw new \coding_exception('Unknown cm state.');
        }
    }

    /**
     * Returns a string based on the state result.
     *
     * @throws \coding_exception
     * @return string
     */
    public function get_state_result() {
        switch ($this->get_stateid()) {
            case self::STATE_NEW:
            case self::STATE_REOPENED:
                return \get_string('state_result_pending',   'local_extension');
            case self::STATE_DENIED:
                return \get_string('state_result_denied',    'local_extension');
            case self::STATE_APPROVED:
                return \get_string('state_result_approved',  'local_extension');
            case self::STATE_CANCEL:
                return \get_string('state_result_cancelled', 'local_extension');
            default:
                throw new \coding_exception('Unknown cm state.');
        }
    }

    /**
     * Returns the cm courseid
     *
     * @return integer
     */
    public function get_courseid() {
        return $this->cm->course;
    }

    /**
     * Returns the cm cmid
     *
     * @return integer
     */
    public function get_cmid() {
        return $this->cm->cmid;
    }

    /**
     * Retuns the cm data.
     *
     * @return mixed
     */
    public function get_data() {
        return $this->cm->data;
    }

    /**
     * Returns the cm state id
     *
     * @return integer
     */
    public function get_stateid() {
        return $this->cm->state;
    }

    /**
     * Set the cm state
     *
     * @param integer $state
     */
    private function set_stateid($state) {
        $this->cm->state = $state;
    }

    /**
     * Set the cm data
     *
     * @param mixed $data
     */
    private function set_data($data) {
        $this->cm->data = $data;
    }

}