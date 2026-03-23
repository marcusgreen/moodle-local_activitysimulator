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
 * Instructor actor for the activity simulator.
 *
 * @package     local_activitysimulator
 * @copyright   2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Developed with assistance from Anthropic Claude (claude.ai).
 */

namespace local_activitysimulator\simulation;

defined('MOODLE_INTERNAL') || die();

// mod/forum/lib.php and mod/assign/locallib.php are require_once'd by window_runner.php
// before this class is instantiated. Loading them here at autoload time is unreliable.

use local_activitysimulator\data\name_generator;

/**
 * Simulates the actions of a single instructor in a single activity window.
 *
 * Called by window_runner after all student actors have run. Running after
 * students ensures the instructor sees all student forum posts from this window.
 *
 * INSTRUCTOR PROFILES
 * -------------------
 * Three profile types control action probabilities:
 *
 *   responsive   — posts every window, reads all posts, replies to 50%, grades 100%
 *   delayed      — posts 80%, reads 60%, replies 20%, grades 60%
 *   unresponsive — posts 30%, reads 20%, replies 5%, grades 20%
 *
 * Phase 1 assigns all instructors the 'responsive' profile.
 *
 * ACTION SEQUENCE
 * ---------------
 * 1. Post announcement in the news forum (profile probability).
 * 2. Read unread discussions in section forums (profile probability per discussion),
 *    with reply probability per discussion read.
 * 3. Grade assignments in this section (profile probability).
 * 4. View gradebook if grading occurred.
 *
 * USER SWITCHING
 * --------------
 * All API calls and event fires wrap $USER in user_switcher via try/finally.
 *
 * RETURN VALUE
 * ------------
 * simulate() returns a \stdClass with:
 *   ->written              int   Total actions taken
 *   ->announcement_posted  bool  Whether an announcement was posted this window
 */
class instructor_actor {

    /**
     * Per-profile probability tables.
     *
     * @var array<string, array<string, float>>
     */
    const PROFILE_PROBABILITIES = [
        'responsive'   => ['announce' => 1.00, 'read_disc' => 1.00, 'reply_disc' => 0.50, 'grade' => 1.00],
        'delayed'      => ['announce' => 0.80, 'read_disc' => 0.60, 'reply_disc' => 0.20, 'grade' => 0.60],
        'unresponsive' => ['announce' => 0.30, 'read_disc' => 0.20, 'reply_disc' => 0.05, 'grade' => 0.20],
    ];

    /** @var log_writer */
    private log_writer $log_writer;

    /** @var content_scanner */
    private content_scanner $scanner;

    /** @var name_generator */
    private name_generator $namegen;

    /** @var int Total windows in term. */
    private int $total_windows;

    /** @var bool Emit per-instructor mtrace() lines. */
    private bool $verbose;

    /**
     * Constructor.
     *
     * @param log_writer      $log_writer
     * @param content_scanner $scanner
     * @param name_generator  $namegen
     * @param int             $total_windows
     * @param bool            $verbose
     */
    public function __construct(
        log_writer $log_writer,
        content_scanner $scanner,
        name_generator $namegen,
        int $total_windows,
        bool $verbose = false
    ) {
        $this->log_writer    = $log_writer;
        $this->scanner       = $scanner;
        $this->namegen       = $namegen;
        $this->total_windows = $total_windows;
        $this->verbose       = $verbose;
    }

    /**
     * Simulates one instructor's actions in one window.
     *
     * @param  int    $userid
     * @param  int    $courseid
     * @param  int    $section
     * @param  int    $window_index
     * @param  string $profile_type 'responsive', 'delayed', or 'unresponsive'
     * @return \stdClass  ->written (int), ->announcement_posted (bool)
     */
    public function simulate(
        int $userid,
        int $courseid,
        int $section,
        int $window_index,
        string $profile_type = 'responsive'
    ): \stdClass {
        $probs               = self::PROFILE_PROBABILITIES[$profile_type]
                               ?? self::PROFILE_PROBABILITIES['responsive'];
        $written             = 0;
        $announcement_posted = false;

        // 1. Post announcement.
        if ($this->roll($probs['announce'])) {
            $posted = $this->post_announcement($userid, $courseid);
            if ($posted) {
                $written++;
                $announcement_posted = true;
            }
        }

        // 2. Read and reply to student discussions in section forums.
        $activities = $this->scanner->get_activities_in_section($section);
        foreach ($activities as $activity) {
            if ($activity->type !== 'forum') {
                continue;
            }
            $written += $this->simulate_forum_reads($userid, $courseid, $activity, $probs);
        }

        // 3. Grade assignments.
        $graded = false;
        if ($this->roll($probs['grade'])) {
            foreach ($activities as $activity) {
                if ($activity->type !== 'assignment') {
                    continue;
                }
                $written += $this->grade_assignment($userid, $courseid, $activity);
                $graded   = true;
            }
        }

        // 4. View gradebook if grading occurred.
        if ($graded) {
            $switcher = new user_switcher($userid);
            try {
                $this->log_writer->fire_view_event($userid, $courseid, 'view_gradebook');
            } finally {
                $switcher->restore();
            }
            $written++;
        }

        $result                      = new \stdClass();
        $result->written             = $written;
        $result->announcement_posted = $announcement_posted;

        if ($this->verbose) {
            global $DB;
            $username = $DB->get_field('user', 'username', ['id' => $userid]) ?? "user$userid";
            mtrace(sprintf('      instructor %s [%s] window %d: %d actions%s',
                $username, $profile_type, $window_index, $written,
                $announcement_posted ? ', announcement posted' : ''
            ));
        }

        return $result;
    }

    // =========================================================================
    // Private: announcement
    // =========================================================================

    /**
     * Posts an announcement in the course news forum using forum_add_discussion().
     *
     * @param  int $userid
     * @param  int $courseid
     * @return bool  True if the announcement was posted successfully.
     */
    private function post_announcement(int $userid, int $courseid): bool {
        global $DB;

        $announcements_cm = $this->scanner->get_announcements_forum();
        if ($announcements_cm === null) {
            return false;
        }

        $discussion              = new \stdClass();
        $discussion->course      = $courseid;
        $discussion->forum       = (int)$announcements_cm->instance;
        $discussion->name        = $this->namegen->get_announcement_subject();
        $discussion->message     = $this->namegen->get_post_text();
        $discussion->messageformat = FORMAT_PLAIN;
        $discussion->messagetrust = 0;
        $discussion->attachmentid = null;
        $discussion->timelocked  = 0;
        $discussion->mailnow     = 0;
        $discussion->timestart   = 0;
        $discussion->timeend     = 0;

        // Build activity descriptor for run_log.
        $activity             = new \stdClass();
        $activity->type       = 'forum';
        $activity->cmid       = (int)$announcements_cm->id;
        $activity->instanceid = (int)$announcements_cm->instance;
        $activity->name       = $announcements_cm->name;
        $activity->section    = 0;
        $activity->duedate    = null;

        $switcher = new user_switcher($userid);
        try {
            $discussionid = \forum_add_discussion($discussion);
        } finally {
            $switcher->restore();
        }

        if (!$discussionid) {
            mtrace("    Warning: forum_add_discussion() returned false for instructor $userid (announcement)");
            return false;
        }

        $firstpost = $DB->get_record_select(
            'forum_posts',
            'discussion = :did AND parent = 0',
            ['did' => $discussionid]
        );

        $this->log_writer->record_api_action(
            $userid, $courseid, 'post_announcement', $activity,
            $firstpost ? (int)$firstpost->id : null,
            'posted'
        );

        return true;
    }

    // =========================================================================
    // Private: forum reads and replies
    // =========================================================================

    /**
     * Reads unread discussions in a section forum, replying to some.
     *
     * @param  int      $userid
     * @param  int      $courseid
     * @param  \stdClass $activity
     * @param  array    $probs     Probability table for this profile.
     * @return int      Actions written.
     */
    private function simulate_forum_reads(
        int $userid,
        int $courseid,
        \stdClass $activity,
        array $probs
    ): int {
        $written = 0;

        $unread = $this->scanner->get_unread_discussions($activity->instanceid, $userid);

        foreach ($unread as $disc) {
            if (!$this->roll($probs['read_disc'])) {
                continue;
            }

            // Fire discussion_viewed event.
            // discussion_viewed requires relateduserid = discussion author.
            // Directed edge for SNA: instructor -> student whose post was read.
            $authorid = (int)$disc->authorid;
            if ($authorid === 0) {
                mtrace("    Warning: discussion {$disc->discussionid} has no authorid, skipping read_forum event");
                continue;
            }
            $switcher = new user_switcher($userid);
            try {
                $this->log_writer->fire_view_event(
                    $userid,
                    $courseid,
                    'read_forum',
                    $activity,
                    (int)$disc->discussionid,
                    null,
                    $authorid
                );
            } finally {
                $switcher->restore();
            }
            $written++;

            // Maybe reply.
            if ($this->roll($probs['reply_disc'])) {
                $written += $this->reply_to_discussion($userid, $courseid, $activity, (int)$disc->discussionid, (int)$disc->authorid);
            }
        }

        return $written;
    }

    /**
     * Replies to a specific discussion using forum_add_post().
     *
     * @param  int      $userid
     * @param  int      $courseid
     * @param  \stdClass $activity
     * @param  int      $discussionid
     * @param  int      $authorid     The discussion author (stored as relateduserid in logstore).
     * @return int      1 on success, 0 on failure.
     */
    private function reply_to_discussion(
        int $userid,
        int $courseid,
        \stdClass $activity,
        int $discussionid,
        int $authorid
    ): int {
        global $DB;

        $parent_post = $DB->get_record_select(
            'forum_posts',
            'discussion = :did AND parent = 0',
            ['did' => $discussionid],
            '*',
            MUST_EXIST
        );

        $post              = new \stdClass();
        $post->discussion  = $discussionid;
        $post->parent      = $parent_post->id;
        $post->userid      = $userid;
        $post->message     = $this->namegen->get_post_text();
        $post->messageformat = FORMAT_PLAIN;
        $post->messagetrust  = 0;
        $post->attachments   = null;
        $post->mailnow       = 0;
        $post->itemid        = 0;  // No draft file area; required by forum_add_new_post.

        $switcher = new user_switcher($userid);
        try {
            $postid = \forum_add_new_post($post, false);
        } finally {
            $switcher->restore();
        }

        if (!$postid) {
            mtrace("    Warning: forum_add_new_post() returned false for instructor $userid");
            return 0;
        }

        $this->log_writer->record_api_action(
            $userid, $courseid, 'reply_forum', $activity,
            (int)$postid, 'posted'
        );

        return 1;
    }

    // =========================================================================
    // Private: grading
    // =========================================================================

    /**
     * Grades all submitted assignments using the assign API.
     *
     * Finds all submissions with status 'submitted' that have not yet
     * received a grade, and grades each one with a score scaled by a
     * random factor (simulating instructor variation).
     *
     * Assignment grading is a Stage 2 concern — this is a lightweight
     * stub that records a grade_assignment action without using the full
     * assign grading API. Full assign API grading is implemented in Stage 2.
     *
     * @param  int      $userid     Instructor user id.
     * @param  int      $courseid
     * @param  \stdClass $activity
     * @return int      Actions written.
     */
    private function grade_assignment(int $userid, int $courseid, \stdClass $activity): int {
        global $DB;

        // Find ungraded submissions for this assignment.
        $sql = "SELECT s.id, s.userid
                  FROM {assign_submission} s
             LEFT JOIN {assign_grades} g
                    ON g.assignment = s.assignment AND g.userid = s.userid
                 WHERE s.assignment = :assignid
                   AND s.status = 'submitted'
                   AND g.id IS NULL";

        $submissions = $DB->get_records_sql($sql, ['assignid' => $activity->instanceid]);

        if (empty($submissions)) {
            return 0;
        }

        // Stage 2 will use the full assign API to write grade records.
        // For now, record the grading action in run_log only.
        foreach ($submissions as $submission) {
            $this->log_writer->record_api_action(
                $userid, $courseid, 'grade_assignment', $activity,
                (int)$submission->id, 'graded_stub'
            );
        }

        return count($submissions);
    }

    // =========================================================================
    // Private: helpers
    // =========================================================================

    /**
     * Returns true with the given probability.
     *
     * @param  float $probability 0.0–1.0
     * @return bool
     */
    private function roll(float $probability): bool {
        return (mt_rand(1, 1000) / 1000.0) <= $probability;
    }
}
