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
 * User manager for the activity simulator.
 *
 * @package     local_activitysimulator
 * @copyright   2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Developed with assistance from Anthropic Claude (claude.ai).
 */

namespace local_activitysimulator\manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/cohort/lib.php');

use local_activitysimulator\data\name_generator;
use local_activitysimulator\data\stats_helper;

/**
 * Creates and verifies the pool of simulated users.
 *
 * The user pool consists of students in four behaviour groups and a set of
 * instructors. Group sizes are read from plugin settings and default to the
 * values in the original spec (20/140/20/20 students, 2 instructors).
 *
 * USERNAME CONVENTION
 * -------------------
 * Usernames match SIS-style student IDs with a group prefix and zero-padded
 * three-digit sequence number:
 *
 *   a001–a{n}   Overachievers
 *   b001–b{n}   Standard
 *   c001–c{n}   Intermittent
 *   f001–f{n}   Failing
 *   t001–t{n}   Instructors
 *
 * The prefix letters and padding width are fixed by the SIS ID convention
 * and are not configurable. Group sizes are configurable.
 *
 * IDEMPOTENCY
 * -----------
 * ensure_users_exist() is safe to call multiple times. It checks for each
 * username before creating the user, and checks for an existing learner
 * profile before inserting one. Diligence scalars are never re-generated
 * for existing users — the scalar is persistent across terms by design.
 *
 * COHORT
 * ------
 * All 202 users (by default) are added to a cohort named "Simulated Users".
 * The cohort is created if it does not exist. This makes it easy to identify
 * and manage the synthetic population as a group.
 *
 * PASSWORD
 * --------
 * All simulated users are created with the password defined by
 * SIMULATOR_USER_PASSWORD below. This plugin is intended for use on a
 * dedicated test Moodle instance. Do not run it on a production site.
 */
class user_manager {

    /** @var string Password assigned to all simulated users. */
    const SIMULATOR_USER_PASSWORD = 'Simulator1!';

    /** @var string Cohort name for all simulated users. */
    const COHORT_NAME = 'Simulated Users';

    /** @var string Cohort idnumber for programmatic lookup. */
    const COHORT_IDNUMBER = 'local_activitysimulator_users';

    /** @var string Email domain. Uses RFC-reserved .invalid TLD — will never route. */
    const EMAIL_DOMAIN = 'simulator.invalid';

    /**
     * Group type constants — match the values stored in
     * local_activitysimulator_learner_profiles.group_type.
     */
    const GROUP_OVERACHIEVER = 'overachiever';
    const GROUP_STANDARD     = 'standard';
    const GROUP_INTERMITTENT = 'intermittent';
    const GROUP_FAILING      = 'failing';

    /** @var \stdClass Plugin config, loaded once in constructor. */
    private \stdClass $config;

    /** @var name_generator */
    private name_generator $namegen;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->config  = get_config('local_activitysimulator');
        $this->namegen = new name_generator();
    }

    /**
     * Ensures all simulated users exist, creating any that are missing.
     *
     * Also ensures all student users have a learner profile row, and that
     * all users are members of the "Simulated Users" cohort.
     *
     * Safe to call repeatedly — existing users and profiles are not modified.
     *
     * @param  bool $verbose If true, emit mtrace() lines for each created user.
     * @return array ['created' => int, 'existing' => int, 'profiles_created' => int]
     */
    public function ensure_users_exist(bool $verbose = false): array {
        global $DB;

        $stats = ['created' => 0, 'existing' => 0, 'profiles_created' => 0];

        $cohortid = $this->ensure_cohort_exists();

        // Seed name generation from the next available user id so names are
        // distributed across the full first/last name pools, not a small subset.
        $next_id      = (int)$DB->get_field_sql('SELECT COALESCE(MAX(id), 0) FROM {user}') + 1;
        $created_before = 0;

        foreach ($this->user_definitions() as $def) {
            $userid = $this->ensure_user($def, $stats, $verbose, $next_id);
            if ($stats['created'] > $created_before) {
                $next_id++;
                $created_before = $stats['created'];
            }

            // Create learner profile for students (not instructors).
            if ($def['group'] !== null) {
                $created = $this->ensure_learner_profile($userid, $def['group']);
                if ($created) {
                    $stats['profiles_created']++;
                }
            }

            // Add to cohort if not already a member.
            $this->ensure_cohort_member($cohortid, $userid);
        }

        return $stats;
    }

    /**
     * Returns an array of user IDs for the given group type.
     *
     * Queries the learner_profiles table, so users must already exist.
     * Used by term_manager and window_runner when building enrolment lists.
     *
     * @param  string $group One of the GROUP_* constants.
     * @return int[]  Moodle user IDs.
     */
    public function get_userids_by_group(string $group): array {
        global $DB;
        return $DB->get_fieldset_select(
            'local_activitysimulator_learner_profiles',
            'userid',
            'group_type = ?',
            [$group]
        );
    }

    /**
     * Returns an array of user IDs for all instructors (t001…tN).
     *
     * @return int[]
     */
    public function get_instructor_userids(): array {
        global $DB;
        $count    = (int)($this->config->instructors_per_course ?? 2);
        $usernames = [];
        for ($i = 1; $i <= $count; $i++) {
            $usernames[] = 't' . str_pad($i, 3, '0', STR_PAD_LEFT);
        }
        if (empty($usernames)) {
            return [];
        }
        [$sql, $params] = $DB->get_in_or_equal($usernames);
        return $DB->get_fieldset_select('user', 'id', "username $sql", $params);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the full list of user definitions to create.
     *
     * Each entry is an array with keys:
     *   'username' string
     *   'group'    string|null  — null for instructors
     *
     * Group sizes are read from plugin settings with spec defaults as fallback.
     *
     * @return array[]
     */
    private function user_definitions(): array {
        $counts = [
            self::GROUP_OVERACHIEVER => (int)($this->config->group_count_overachiever ?? 20),
            self::GROUP_STANDARD     => (int)($this->config->group_count_standard     ?? 140),
            self::GROUP_INTERMITTENT => (int)($this->config->group_count_intermittent ?? 20),
            self::GROUP_FAILING      => (int)($this->config->group_count_failing      ?? 20),
        ];
        $instructor_count = (int)($this->config->instructors_per_course ?? 2);

        $prefixes = [
            self::GROUP_OVERACHIEVER => 'a',
            self::GROUP_STANDARD     => 'b',
            self::GROUP_INTERMITTENT => 'c',
            self::GROUP_FAILING      => 'f',
        ];

        $defs = [];

        foreach ($counts as $group => $n) {
            $prefix = $prefixes[$group];
            for ($i = 1; $i <= $n; $i++) {
                $defs[] = [
                    'username' => $prefix . str_pad($i, 3, '0', STR_PAD_LEFT),
                    'group'    => $group,
                ];
            }
        }

        for ($i = 1; $i <= $instructor_count; $i++) {
            $defs[] = [
                'username' => 't' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'group'    => null,
            ];
        }

        return $defs;
    }

    /**
     * Ensures a single Moodle user exists. Creates it if missing.
     *
     * @param  array $def     User definition from user_definitions().
     * @param  array &$stats  Running stats array, updated in place.
     * @param  bool  $verbose Emit mtrace on creation.
     * @return int   Moodle user ID.
     */
    private function ensure_user(array $def, array &$stats, bool $verbose, int $next_id = 0): int {
        global $DB;

        $existing = $DB->get_record('user', ['username' => $def['username'], 'deleted' => 0]);
        if ($existing) {
            $stats['existing']++;
            return (int)$existing->id;
        }

        $userobj = new \stdClass();
        $userobj->username  = $def['username'];
        $userobj->password  = hash_internal_user_password(self::SIMULATOR_USER_PASSWORD);
        $name = $this->namegen->get_name_for_userid($next_id);
        $userobj->firstname = $name['firstname'];
        $userobj->lastname  = $name['lastname'];
        $userobj->email     = $def['username'] . '@' . self::EMAIL_DOMAIN;
        $userobj->auth      = 'manual';
        $userobj->confirmed = 1;
        $userobj->mnethostid = 1; // Local Moodle instance.
        $userobj->timecreated  = time();
        $userobj->timemodified = time();

        $userid = user_create_user($userobj, false, false);

        $stats['created']++;
        if ($verbose) {
            mtrace("  Created user: {$def['username']} ({$userobj->firstname} {$userobj->lastname})");
        }

        return (int)$userid;
    }

    /**
     * Ensures a learner profile row exists for the given user.
     *
     * If a profile already exists for this userid, it is left unchanged —
     * the diligence scalar is persistent and must not be re-generated.
     *
     * @param  int    $userid
     * @param  string $group  One of the GROUP_* constants.
     * @return void
     */
    private function ensure_learner_profile(int $userid, string $group): bool {
        global $DB;

        if ($DB->record_exists('local_activitysimulator_learner_profiles', ['userid' => $userid])) {
            return false;
        }

        $scalar = $this->generate_diligence_scalar($group);

        $record = new \stdClass();
        $record->userid           = $userid;
        $record->group_type       = $group;
        $record->diligence_scalar = $scalar;
        $record->scalar_source    = 'generated';
        $record->timecreated      = time();
        $record->timemodified     = time();

        $DB->insert_record('local_activitysimulator_learner_profiles', $record);
        return true;
    }

    /**
     * Generates a diligence scalar for the given group using a truncated
     * normal distribution. Parameters are read from plugin settings with
     * the spec defaults as fallback.
     *
     * The scalar is rounded to 3 decimal places to match the DECIMAL(4,3)
     * column definition in the database schema.
     *
     * @param  string $group One of the GROUP_* constants.
     * @return float         Value in the range defined for this group.
     */
    private function generate_diligence_scalar(string $group): float {
        // Default parameters match the spec design values.
        $defaults = [
            self::GROUP_OVERACHIEVER => ['mean' => 0.94, 'stddev' => 0.05, 'min' => 0.85, 'max' => 1.00],
            self::GROUP_STANDARD     => ['mean' => 0.73, 'stddev' => 0.08, 'min' => 0.60, 'max' => 0.85],
            self::GROUP_INTERMITTENT => ['mean' => 0.45, 'stddev' => 0.10, 'min' => 0.30, 'max' => 0.60],
            self::GROUP_FAILING      => ['mean' => 0.18, 'stddev' => 0.09, 'min' => 0.05, 'max' => 0.35],
        ];

        $d = $defaults[$group];

        // Read from settings if available; fall back to defaults.
        $mean   = (float)($this->config->{"diligence_mean_{$group}"}   ?? $d['mean']);
        $stddev = (float)($this->config->{"diligence_stddev_{$group}"} ?? $d['stddev']);

        // Range bounds are not currently exposed as settings — they are
        // derived from the group design. If settings-driven bounds are
        // added in a future phase, replace these with config reads.
        $min = $d['min'];
        $max = $d['max'];

        $scalar = stats_helper::truncated_normal($mean, $stddev, $min, $max);
        return stats_helper::round_to($scalar, 3);
    }

    /**
     * Ensures the "Simulated Users" cohort exists, creating it if not.
     *
     * @return int Cohort ID.
     */
    private function ensure_cohort_exists(): int {
        global $DB;

        $cohort = $DB->get_record('cohort', ['idnumber' => self::COHORT_IDNUMBER]);
        if ($cohort) {
            return (int)$cohort->id;
        }

        $cohort = new \stdClass();
        $cohort->name        = self::COHORT_NAME;
        $cohort->idnumber    = self::COHORT_IDNUMBER;
        $cohort->description = 'Automatically managed cohort of users created by local_activitysimulator. Do not edit manually.';
        $cohort->descriptionformat = FORMAT_PLAIN;
        $cohort->contextid   = \context_system::instance()->id;
        $cohort->component   = 'local_activitysimulator';
        $cohort->timecreated  = time();
        $cohort->timemodified = time();

        return (int)cohort_add_cohort($cohort);
    }

    /**
     * Adds a user to the cohort if they are not already a member.
     *
     * @param  int $cohortid
     * @param  int $userid
     * @return void
     */
    private function ensure_cohort_member(int $cohortid, int $userid): void {
        if (!cohort_is_member($cohortid, $userid)) {
            cohort_add_member($cohortid, $userid);
        }
    }
}
