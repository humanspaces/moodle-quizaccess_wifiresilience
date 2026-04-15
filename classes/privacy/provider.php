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
 * Privacy Subsystem implementation for quizaccess_wifiresilience.
 *
 * @package     quizaccess_wifiresilience
 * @copyright   2026 ISB Bayern
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_wifiresilience\privacy;

use context;
use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for quizaccess_wifiresilience.
 *
 * @package     quizaccess_wifiresilience
 * @copyright   2026 Paul Baumgart-Ouahid
 * @author      Paul Baumgart-Ouahid <paul@humanspaces.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Returns metadata.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('quizaccess_wifiresilience_er', [
            'quizid' => 'privacy:metadata:quizaccess_wifiresilience_er:quizid',
            'userid' => 'privacy:metadata:quizaccess_wifiresilience_er:userid',
            'attempt' => 'privacy:metadata:quizaccess_wifiresilience_er:attempt',
            'answer_plain' => 'privacy:metadata:quizaccess_wifiresilience_er:answer_plain',
            'answer_encrypted' => 'privacy:metadata:quizaccess_wifiresilience_er:answer_encrypted',
            'timecreated' => 'privacy:metadata:quizaccess_wifiresilience_er:timecreated',
        ], 'privacy:metadata:quizaccess_wifiresilience_er');

        $collection->add_database_table('quizaccess_wifiresilience_sess', [
            'attemptid' => 'privacy:metadata:quizaccess_wifiresilience_sess:attemptid',
            'userid' => 'privacy:metadata:quizaccess_wifiresilience_sess:userid',
            'sesskey' => 'privacy:metadata:quizaccess_wifiresilience_sess:sesskey',
        ], 'privacy:metadata:quizaccess_wifiresilience_sess');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user
     * information for the specified user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'modulename' => 'quiz',
            'userid' => $userid,
        ];

        $sql = "SELECT ctx.id
                  FROM {quizaccess_wifiresilience_er} er
                  JOIN {course_modules} cm
                    ON cm.instance = er.quizid
                  JOIN {modules} m
                    ON m.id = cm.module
                   AND m.name = :modulename
                  JOIN {context} ctx
                    ON ctx.instanceid = cm.id
                   AND ctx.contextlevel = :contextlevel
                 WHERE er.userid = :userid
              GROUP BY ctx.id";
        $contextlist->add_from_sql($sql, $params);

        $sql = "SELECT ctx.id
                  FROM {quizaccess_wifiresilience_sess} sess
                  JOIN {quiz_attempts} qa
                    ON qa.id = sess.attemptid
                  JOIN {course_modules} cm
                    ON cm.instance = qa.quiz
                  JOIN {modules} m
                    ON m.id = cm.module
                   AND m.name = :modulename
                  JOIN {context} ctx
                    ON ctx.instanceid = cm.id
                   AND ctx.contextlevel = :contextlevel
                 WHERE sess.userid = :userid
              GROUP BY ctx.id";
        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Export all user data for the specified user,
     * in the specified contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }

            $quizid = static::get_quizid_from_context($context);
            $pluginname = get_string('pluginname', 'quizaccess_wifiresilience');

            $errecords = $DB->get_records('quizaccess_wifiresilience_er', [
                'quizid' => $quizid,
                'userid' => $userid,
            ], 'id ASC');

            $index = 0;
            foreach ($errecords as $record) {
                $index++;
                writer::with_context($context)->export_data([
                    $pluginname,
                    'quizaccess_wifiresilience_er',
                    $index,
                ], (object) [
                    'quizid' => $record->quizid,
                    'userid' => $record->userid,
                    'attempt' => $record->attempt,
                    'answer_plain' => $record->answer_plain,
                    'answer_encrypted' => $record->answer_encrypted,
                    'timecreated' => transform::datetime($record->timecreated),
                ]);
            }

            $sql = "SELECT sess.*
                      FROM {quizaccess_wifiresilience_sess} sess
                      JOIN {quiz_attempts} qa
                        ON qa.id = sess.attemptid
                     WHERE qa.quiz = :quizid
                       AND sess.userid = :userid
                  ORDER BY sess.id ASC";
            $sessrecords = $DB->get_records_sql($sql, ['quizid' => $quizid, 'userid' => $userid]);

            $index = 0;
            foreach ($sessrecords as $record) {
                $index++;
                writer::with_context($context)->export_data([
                    $pluginname,
                    'quizaccess_wifiresilience_sess',
                    $index,
                ], (object) [
                    'attemptid' => $record->attemptid,
                    'userid' => $record->userid,
                    'sesskey' => $record->sesskey,
                ]);
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param context $context
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        global $DB;

        if (!$context instanceof context_module) {
            return;
        }

        $quizid = static::get_quizid_from_context($context);
        $DB->delete_records('quizaccess_wifiresilience_er', ['quizid' => $quizid]);

        $attemptids = static::get_attemptids_for_quiz($quizid);
        if (!empty($attemptids)) {
            $DB->delete_records_list('quizaccess_wifiresilience_sess', 'attemptid', $attemptids);
        }
    }

    /**
     * Delete all user data for the specified user,
     * in the specified contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }

            $quizid = static::get_quizid_from_context($context);
            $DB->delete_records('quizaccess_wifiresilience_er', [
                'quizid' => $quizid,
                'userid' => $userid,
            ]);

            $attemptids = static::get_attemptids_for_quiz($quizid);
            if (empty($attemptids)) {
                continue;
            }

            [$insql, $params] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED, 'attemptid');
            $params['userid'] = $userid;
            $DB->delete_records_select('quizaccess_wifiresilience_sess', "userid = :userid AND attemptid {$insql}", $params);
        }
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if (!$context instanceof context_module) {
            return;
        }

        $quizid = static::get_quizid_from_context($context);

        $sql = "SELECT userid
                  FROM {quizaccess_wifiresilience_er}
                 WHERE quizid = :quizider
                UNION
                SELECT sess.userid AS userid
                  FROM {quizaccess_wifiresilience_sess} sess
                  JOIN {quiz_attempts} qa
                    ON qa.id = sess.attemptid
                 WHERE qa.quiz = :quizidsess";

        $userlist->add_from_sql('userid', $sql, [
            'quizider' => $quizid,
            'quizidsess' => $quizid,
        ]);
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        $quizid = static::get_quizid_from_context($context);
        [$userinsql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'userid');

        $params = array_merge(['quizid' => $quizid], $userparams);
        $DB->delete_records_select('quizaccess_wifiresilience_er', "quizid = :quizid AND userid {$userinsql}", $params);

        $attemptids = static::get_attemptids_for_quiz($quizid);
        if (empty($attemptids)) {
            return;
        }

        [$attemptinsql, $attemptparams] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED, 'attemptid');
        $params = array_merge($userparams, $attemptparams);
        $DB->delete_records_select('quizaccess_wifiresilience_sess', "userid {$userinsql} AND attemptid {$attemptinsql}", $params);
    }

    /**
     * Get the quiz id for the supplied context.
     *
     * @param context_module $context
     * @return int
     */
    protected static function get_quizid_from_context(context_module $context): int {
        global $DB;

        return (int) $DB->get_field('course_modules', 'instance', ['id' => $context->instanceid], MUST_EXIST);
    }

    /**
     * Get all attempt ids for a quiz.
     *
     * @param int $quizid
     * @return array
     */
    protected static function get_attemptids_for_quiz(int $quizid): array {
        global $DB;

        return $DB->get_fieldset_select('quiz_attempts', 'id', 'quiz = :quizid', ['quizid' => $quizid]);
    }
}
