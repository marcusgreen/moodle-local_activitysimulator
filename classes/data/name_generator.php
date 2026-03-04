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
 * Synthetic name, course title, and post text generator.
 *
 * All word lists are loaded from lang strings (see lang/en/local_activitysimulator.php)
 * so they can be overridden via Moodle's standard lang customisation mechanism
 * without modifying plugin code.
 *
 * The name generation logic is adapted from local_pseudonymise
 * (Elizabeth Dalton, https://github.com/elizabethdalton/pseudonymise, GPL v3).
 * Functions have been restructured as instance methods so that multiple
 * generator instances do not share counter state.
 *
 * @package     local_activitysimulator
 * @copyright   2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Developed with assistance from Anthropic Claude (claude.ai).
 */

namespace local_activitysimulator\data;

defined('MOODLE_INTERNAL') || die();

/**
 * Generates synthetic names, course titles, section labels, and post text
 * for simulated users and courses.
 *
 * Usage:
 *   $gen = new name_generator();
 *   $firstname = $gen->get_firstname();
 *   $lastname  = $gen->get_lastname();
 *   $course    = $gen->get_course_name();
 *   $section   = $gen->get_section_name();
 *   $post      = $gen->get_post_text();
 *
 * Each method advances an internal counter so successive calls produce
 * different values. Counters wrap around when the list is exhausted.
 *
 * POST TEXT NOTE: get_post_text() cycles through 26 Edward Lear nonsense
 * verses. When the verse list is exhausted it starts again from the
 * beginning. Most installations will cycle the list many times. This is
 * intentional — post content has no semantic meaning in this simulation.
 * Analytics tools operate on authorship, timing, and submission metadata.
 * See lang/en/local_activitysimulator.php ('gen_post_verses') for the full
 * verse list and instructions for substituting different text.
 */
class name_generator {

    /** @var array Loaded given names (first names). */
    private array $given_names = [];

    /** @var array Loaded family names (surnames). */
    private array $family_names = [];

    /** @var array Loaded course qualifiers. */
    private array $course_qualifiers = [];

    /** @var array Loaded course subjects. */
    private array $course_subjects = [];

    /** @var array Loaded course prepositions. */
    private array $course_prepositions = [];

    /** @var array Loaded course disciplines. */
    private array $course_disciplines = [];

    /** @var array Loaded section adjectives. */
    private array $section_adjectives = [];

    /** @var array Loaded section nouns. */
    private array $section_nouns = [];

    /** @var array Loaded post verses. */
    private array $post_verses = [];

    /** @var int Counter for course names. */
    private int $course_index = 0;

    /** @var int Counter for section names. */
    private int $section_index = 0;

    /** @var int Counter for post verses. */
    private int $verse_index = 0;

    /**
     * Constructor. Loads all word lists from lang strings.
     */
    public function __construct() {
        $this->given_names        = $this->load_list('gen_given_names');
        $this->family_names       = $this->load_list('gen_family_names');
        $this->course_qualifiers  = $this->load_list('gen_course_qualifiers');
        $this->course_subjects    = $this->load_list('gen_course_subjects');
        $this->course_prepositions = $this->load_list('gen_course_prepositions');
        $this->course_disciplines = $this->load_list('gen_course_disciplines');
        $this->section_adjectives = $this->load_list('gen_section_adjectives');
        $this->section_nouns      = $this->load_list('gen_section_nouns');
        $this->post_verses        = $this->load_list('gen_post_verses');

        // Shuffle course/section lists so titles vary across runs.
        // Name lists are NOT shuffled — order is fixed so username derivation
        // always produces the same name for the same username.
        shuffle($this->course_qualifiers);
        shuffle($this->course_subjects);
        shuffle($this->course_disciplines);
        shuffle($this->section_adjectives);
        shuffle($this->section_nouns);
    }

    /**
     * Derives a deterministic human-readable name from a Moodle user ID.
     *
     * First name index  = $userid % count(given_names)
     *   — cycles through all first names before repeating.
     *
     * Last name index   = ($userid % count(family_names)
     *                      + intdiv($userid, count(given_names))) % count(family_names)
     *   — cycles at a different rate from first names, and shifts when
     *     first names wrap, minimising repeated full-name combinations.
     *
     * At 500 users with ~180 first names and ~110 last names, every first
     * name appears at most 3 times and last names are spread across ~50
     * distinct values rather than the 3–5 seen with the old username approach.
     *
     * @param  int   $userid  Moodle user ID (or predicted next ID).
     * @return array ['firstname' => string, 'lastname' => string]
     */
    public function get_name_for_userid(int $userid): array {
        $fn_count = count($this->given_names);
        $ln_count = count($this->family_names);

        $firstname_index = $userid % $fn_count;
        $lastname_index  = ($userid % $ln_count + intdiv($userid, $fn_count)) % $ln_count;

        return [
            'firstname' => $this->given_names[$firstname_index],
            'lastname'  => $this->family_names[$lastname_index],
        ];
    }

    /**
     * Derives a deterministic human-readable name from a simulated username.
     *
     * @deprecated Use get_name_for_userid() instead.
     * @param  string $username  e.g. 'b187', 't001'
     * @return array  ['firstname' => string, 'lastname' => string]
     */
    public function get_name_for_username(string $username): array {
        $letter_map = ['a' => 0, 'b' => 1, 'c' => 2, 'f' => 3, 't' => 4];

        if (!preg_match('/^([abcft])(\d)(\d)(\d)$/', $username, $m)) {
            // Unrecognised format — use username as fallback.
            return ['firstname' => $username, 'lastname' => 'User'];
        }

        $letter   = $m[1];
        $hundreds = (int)$m[2];
        $tens     = (int)$m[3];
        $ones     = (int)$m[4];

        $letter_index = $letter_map[$letter] ?? 0;

        $firstname_index = ($hundreds * 10 + $tens) % count($this->given_names);
        $surname_index   = ($letter_index * 10 + $ones) % count($this->family_names);

        return [
            'firstname' => $this->given_names[$firstname_index],
            'lastname'  => $this->family_names[$surname_index],
        ];
    }

    /**
     * Returns the next synthetic course title.
     *
     * Produces titles in the form "[qualifier] [subject] [preposition] [discipline]",
     * e.g. "Advanced Studies in Comparative Literature".
     *
     * The four word lists are advanced independently so that combinations
     * are varied rather than lockstepped.
     *
     * @return string
     */
    public function get_course_name(): string {
        $n = $this->course_index;
        $qualifier  = $this->course_qualifiers[$n % count($this->course_qualifiers)];
        $subject    = $this->course_subjects[intdiv($n, count($this->course_qualifiers)) % count($this->course_subjects)];
        $prep       = $this->course_prepositions[$n % count($this->course_prepositions)];
        $discipline = $this->course_disciplines[$n % count($this->course_disciplines)];
        $this->course_index++;
        return "$qualifier $subject $prep $discipline";
    }

    /**
     * Returns the next synthetic section label.
     *
     * Produces labels in the form "[adjective] [noun]",
     * e.g. "Foundational Concepts", "Applied Frameworks".
     *
     * @return string
     */
    public function get_section_name(): string {
        $n    = $this->section_index;
        $adj  = $this->section_adjectives[$n % count($this->section_adjectives)];
        $noun = $this->section_nouns[intdiv($n, count($this->section_adjectives)) % count($this->section_nouns)];
        $this->section_index++;
        return "$adj $noun";
    }

    /**
     * Returns the next forum post body text.
     *
     * Cycles through 26 Edward Lear nonsense verses. When all verses have
     * been returned, the cycle restarts silently from verse 1. Many posts
     * will share the same verse text — this is intentional. See class
     * docblock for rationale.
     *
     * @return string
     */
    public function get_post_text(): string {
        $verse = $this->post_verses[$this->verse_index % count($this->post_verses)];
        $this->verse_index++;
        return $verse;
    }

    /**
     * Returns a subject line for a student forum discussion.
     *
     * Uses the same Lear verse cycle as get_post_text() so that subject
     * lines are varied but clearly synthetic.
     *
     * @return string
     */
    public function get_discussion_subject(): string {
        // Use the first line of the next verse as a subject line.
        $verse = $this->post_verses[$this->verse_index % count($this->post_verses)];
        // Take just the first sentence or up to 80 chars.
        $subject = substr(strtok($verse, '.!?'), 0, 80);
        return trim($subject) ?: 'Discussion post';
    }

    /**
     * Returns a subject line for an instructor announcement.
     *
     * Cycles through a small fixed set of announcement-style subjects.
     * Content is clearly synthetic but plausible in tone.
     *
     * @return string
     */
    public function get_announcement_subject(): string {
        $subjects = [
            'Week recap and upcoming activities',
            'Reminder: check this week\'s materials',
            'Looking ahead to next section',
            'Feedback on recent submissions',
            'Course update and reminders',
            'Great work this week — a note from your instructor',
            'Tips for the upcoming activities',
        ];
        // Cycle through subjects based on verse_index to avoid repeating.
        return $subjects[$this->verse_index % count($subjects)];
    }

    /**
     * Resets all internal counters to zero.
     *
     * Useful in testing when you need reproducible output from a fresh state.
     *
     * @return void
     */
    public function reset_counters(): void {
        $this->course_index  = 0;
        $this->section_index = 0;
        $this->verse_index   = 0;
    }

    /**
     * Loads a pipe-delimited lang string as an array.
     *
     * @param  string $key Lang string key in local_activitysimulator.
     * @return array       Array of trimmed, non-empty items.
     */
    private function load_list(string $key): array {
        $raw   = get_string($key, 'local_activitysimulator');
        $items = array_map('trim', explode('|', $raw));
        // Filter out any empty strings that might result from trailing pipes.
        return array_values(array_filter($items, fn($s) => $s !== ''));
    }
}
