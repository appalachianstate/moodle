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

namespace mod_forum;

use mod_forum_generator;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/forum/lib.php');
require_once($CFG->dirroot . '/mod/forum/locallib.php');
require_once($CFG->dirroot . '/rating/lib.php');

/**
 * The mod_forum lib.php tests.
 *
 * @package    mod_forum
 * @copyright  2013 Frédéric Massart
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lib_test extends \advanced_testcase {

    public function setUp(): void {
        parent::setUp();
        // We must clear the subscription caches. This has to be done both before each test, and after in case of other
        // tests using these functions.
        \mod_forum\subscriptions::reset_forum_cache();
    }

    public function tearDown(): void {
        // We must clear the subscription caches. This has to be done both before each test, and after in case of other
        // tests using these functions.
        \mod_forum\subscriptions::reset_forum_cache();
        parent::tearDown();
    }

    public function test_forum_trigger_content_uploaded_event(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', array('course' => $course->id));
        $context = \context_module::instance($forum->cmid);

        $this->setUser($user->id);
        $fakepost = (object) array('id' => 123, 'message' => 'Yay!', 'discussion' => 100);
        $cm = get_coursemodule_from_instance('forum', $forum->id);

        $fs = get_file_storage();
        $dummy = (object) array(
            'contextid' => $context->id,
            'component' => 'mod_forum',
            'filearea' => 'attachment',
            'itemid' => $fakepost->id,
            'filepath' => '/',
            'filename' => 'myassignmnent.pdf'
        );
        $fi = $fs->create_file_from_string($dummy, 'Content of ' . $dummy->filename);

        $data = new \stdClass();
        $sink = $this->redirectEvents();
        forum_trigger_content_uploaded_event($fakepost, $cm, 'some triggered from value');
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf('\mod_forum\event\assessable_uploaded', $event);
        $this->assertEquals($context->id, $event->contextid);
        $this->assertEquals($fakepost->id, $event->objectid);
        $this->assertEquals($fakepost->message, $event->other['content']);
        $this->assertEquals($fakepost->discussion, $event->other['discussionid']);
        $this->assertCount(1, $event->other['pathnamehashes']);
        $this->assertEquals($fi->get_pathnamehash(), $event->other['pathnamehashes'][0]);
        $expected = new \stdClass();
        $expected->modulename = 'forum';
        $expected->name = 'some triggered from value';
        $expected->cmid = $forum->cmid;
        $expected->itemid = $fakepost->id;
        $expected->courseid = $course->id;
        $expected->userid = $user->id;
        $expected->content = $fakepost->message;
        $expected->pathnamehashes = array($fi->get_pathnamehash());
        $this->assertEventContextNotUsed($event);
    }

    public function test_forum_get_courses_user_posted_in(): void {
        $this->resetAfterTest();

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();

        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $course3 = $this->getDataGenerator()->create_course();

        // Create 3 forums, one in each course.
        $record = new \stdClass();
        $record->course = $course1->id;
        $forum1 = $this->getDataGenerator()->create_module('forum', $record);

        $record = new \stdClass();
        $record->course = $course2->id;
        $forum2 = $this->getDataGenerator()->create_module('forum', $record);

        $record = new \stdClass();
        $record->course = $course3->id;
        $forum3 = $this->getDataGenerator()->create_module('forum', $record);

        // Add a second forum in course 1.
        $record = new \stdClass();
        $record->course = $course1->id;
        $forum4 = $this->getDataGenerator()->create_module('forum', $record);

        // Add discussions to course 1 started by user1.
        $record = new \stdClass();
        $record->course = $course1->id;
        $record->userid = $user1->id;
        $record->forum = $forum1->id;
        $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($record);

        $record = new \stdClass();
        $record->course = $course1->id;
        $record->userid = $user1->id;
        $record->forum = $forum4->id;
        $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($record);

        // Add discussions to course2 started by user1.
        $record = new \stdClass();
        $record->course = $course2->id;
        $record->userid = $user1->id;
        $record->forum = $forum2->id;
        $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($record);

        // Add discussions to course 3 started by user2.
        $record = new \stdClass();
        $record->course = $course3->id;
        $record->userid = $user2->id;
        $record->forum = $forum3->id;
        $discussion3 = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($record);

        // Add post to course 3 by user1.
        $record = new \stdClass();
        $record->course = $course3->id;
        $record->userid = $user1->id;
        $record->forum = $forum3->id;
        $record->discussion = $discussion3->id;
        $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_post($record);

        // User 3 hasn't posted anything, so shouldn't get any results.
        $user3courses = forum_get_courses_user_posted_in($user3);
        $this->assertEmpty($user3courses);

        // User 2 has only posted in course3.
        $user2courses = forum_get_courses_user_posted_in($user2);
        $this->assertCount(1, $user2courses);
        $user2course = array_shift($user2courses);
        $this->assertEquals($course3->id, $user2course->id);
        $this->assertEquals($course3->shortname, $user2course->shortname);

        // User 1 has posted in all 3 courses.
        $user1courses = forum_get_courses_user_posted_in($user1);
        $this->assertCount(3, $user1courses);
        foreach ($user1courses as $course) {
            $this->assertContains($course->id, array($course1->id, $course2->id, $course3->id));
            $this->assertContains($course->shortname, array($course1->shortname, $course2->shortname,
                $course3->shortname));

        }

        // User 1 has only started a discussion in course 1 and 2 though.
        $user1courses = forum_get_courses_user_posted_in($user1, true);
        $this->assertCount(2, $user1courses);
        foreach ($user1courses as $course) {
            $this->assertContains($course->id, array($course1->id, $course2->id));
            $this->assertContains($course->shortname, array($course1->shortname, $course2->shortname));
        }
    }

    /**
     * Test the logic in the forum_tp_can_track_forums() function.
     */
    public function test_forum_tp_can_track_forums(): void {
        global $CFG;

        $this->resetAfterTest();

        $useron = $this->getDataGenerator()->create_user(array('trackforums' => 1));
        $useroff = $this->getDataGenerator()->create_user(array('trackforums' => 0));
        $course = $this->getDataGenerator()->create_course();
        $options = array('course' => $course->id, 'trackingtype' => FORUM_TRACKING_OFF); // Off.
        $forumoff = $this->getDataGenerator()->create_module('forum', $options);

        $options = array('course' => $course->id, 'trackingtype' => FORUM_TRACKING_FORCED); // On.
        $forumforce = $this->getDataGenerator()->create_module('forum', $options);

        $options = array('course' => $course->id, 'trackingtype' => FORUM_TRACKING_OPTIONAL); // Optional.
        $forumoptional = $this->getDataGenerator()->create_module('forum', $options);

        // Allow force.
        $CFG->forum_allowforcedreadtracking = 1;

        // User on, forum off, should be off.
        $result = forum_tp_can_track_forums($forumoff, $useron);
        $this->assertEquals(false, $result);

        // User on, forum on, should be on.
        $result = forum_tp_can_track_forums($forumforce, $useron);
        $this->assertEquals(true, $result);

        // User on, forum optional, should be on.
        $result = forum_tp_can_track_forums($forumoptional, $useron);
        $this->assertEquals(true, $result);

        // User off, forum off, should be off.
        $result = forum_tp_can_track_forums($forumoff, $useroff);
        $this->assertEquals(false, $result);

        // User off, forum force, should be on.
        $result = forum_tp_can_track_forums($forumforce, $useroff);
        $this->assertEquals(true, $result);

        // User off, forum optional, should be off.
        $result = forum_tp_can_track_forums($forumoptional, $useroff);
        $this->assertEquals(false, $result);

        // Don't allow force.
        $CFG->forum_allowforcedreadtracking = 0;

        // User on, forum off, should be off.
        $result = forum_tp_can_track_forums($forumoff, $useron);
        $this->assertEquals(false, $result);

        // User on, forum on, should be on.
        $result = forum_tp_can_track_forums($forumforce, $useron);
        $this->assertEquals(true, $result);

        // User on, forum optional, should be on.
        $result = forum_tp_can_track_forums($forumoptional, $useron);
        $this->assertEquals(true, $result);

        // User off, forum off, should be off.
        $result = forum_tp_can_track_forums($forumoff, $useroff);
        $this->assertEquals(false, $result);

        // User off, forum force, should be off.
        $result = forum_tp_can_track_forums($forumforce, $useroff);
        $this->assertEquals(false, $result);

        // User off, forum optional, should be off.
        $result = forum_tp_can_track_forums($forumoptional, $useroff);
        $this->assertEquals(false, $result);

    }

    /**
     * Test the logic in the test_forum_tp_is_tracked() function.
     */
    public function test_forum_tp_is_tracked(): void {
        global $CFG;

        $this->resetAfterTest();

        $cache = \cache::make('mod_forum', 'forum_is_tracked');
        $useron = $this->getDataGenerator()->create_user(array('trackforums' => 1));
        $useroff = $this->getDataGenerator()->create_user(array('trackforums' => 0));
        $course = $this->getDataGenerator()->create_course();
        $options = array('course' => $course->id, 'trackingtype' => FORUM_TRACKING_OFF); // Off.
        $forumoff = $this->getDataGenerator()->create_module('forum', $options);

        $options = array('course' => $course->id, 'trackingtype' => FORUM_TRACKING_FORCED); // On.
        $forumforce = $this->getDataGenerator()->create_module('forum', $options);

        $options = array('course' => $course->id, 'trackingtype' => FORUM_TRACKING_OPTIONAL); // Optional.
        $forumoptional = $this->getDataGenerator()->create_module('forum', $options);

        // Allow force.
        $CFG->forum_allowforcedreadtracking = 1;

        // User on, forum off, should be off.
        $result = forum_tp_is_tracked($forumoff, $useron);
        $this->assertEquals(false, $result);

        // User on, forum force, should be on.
        $result = forum_tp_is_tracked($forumforce, $useron);
        $this->assertEquals(true, $result);

        // User on, forum optional, should be on.
        $result = forum_tp_is_tracked($forumoptional, $useron);
        $this->assertEquals(true, $result);

        // User off, forum off, should be off.
        $result = forum_tp_is_tracked($forumoff, $useroff);
        $this->assertEquals(false, $result);

        // User off, forum force, should be on.
        $result = forum_tp_is_tracked($forumforce, $useroff);
        $this->assertEquals(true, $result);

        // User off, forum optional, should be off.
        $result = forum_tp_is_tracked($forumoptional, $useroff);
        $this->assertEquals(false, $result);

        $cache->purge();
        // Don't allow force.
        $CFG->forum_allowforcedreadtracking = 0;

        // User on, forum off, should be off.
        $result = forum_tp_is_tracked($forumoff, $useron);
        $this->assertEquals(false, $result);

        // User on, forum force, should be on.
        $result = forum_tp_is_tracked($forumforce, $useron);
        $this->assertEquals(true, $result);

        // User on, forum optional, should be on.
        $result = forum_tp_is_tracked($forumoptional, $useron);
        $this->assertEquals(true, $result);

        // User off, forum off, should be off.
        $result = forum_tp_is_tracked($forumoff, $useroff);
        $this->assertEquals(false, $result);

        // User off, forum force, should be off.
        $result = forum_tp_is_tracked($forumforce, $useroff);
        $this->assertEquals(false, $result);

        // User off, forum optional, should be off.
        $result = forum_tp_is_tracked($forumoptional, $useroff);
        $this->assertEquals(false, $result);

        // Stop tracking so we can test again.
        forum_tp_stop_tracking($forumforce->id, $useron->id);
        forum_tp_stop_tracking($forumoptional->id, $useron->id);
        forum_tp_stop_tracking($forumforce->id, $useroff->id);
        forum_tp_stop_tracking($forumoptional->id, $useroff->id);

        $cache->purge();
        // Allow force.
        $CFG->forum_allowforcedreadtracking = 1;

        // User on, preference off, forum force, should be on.
        $result = forum_tp_is_tracked($forumforce, $useron);
        $this->assertEquals(true, $result);

        // User on, preference off, forum optional, should be on.
        $result = forum_tp_is_tracked($forumoptional, $useron);
        $this->assertEquals(false, $result);

        // User off, preference off, forum force, should be on.
        $result = forum_tp_is_tracked($forumforce, $useroff);
        $this->assertEquals(true, $result);

        // User off, preference off, forum optional, should be off.
        $result = forum_tp_is_tracked($forumoptional, $useroff);
        $this->assertEquals(false, $result);

        $cache->purge();
        // Don't allow force.
        $CFG->forum_allowforcedreadtracking = 0;

        // User on, preference off, forum force, should be on.
        $result = forum_tp_is_tracked($forumforce, $useron);
        $this->assertEquals(false, $result);

        // User on, preference off, forum optional, should be on.
        $result = forum_tp_is_tracked($forumoptional, $useron);
        $this->assertEquals(false, $result);

        // User off, preference off, forum force, should be off.
        $result = forum_tp_is_tracked($forumforce, $useroff);
        $this->assertEquals(false, $result);

        // User off, preference off, forum optional, should be off.
        $result = forum_tp_is_tracked($forumoptional, $useroff);
        $this->assertEquals(false, $result);
    }

    /**
     * Test the logic in the forum_tp_get_course_unread_posts() function.
     */
    public function test_forum_tp_get_course_unread_posts(): void {
        global $CFG;

        $this->resetAfterTest();

        $useron = $this->getDataGenerator()->create_user(array('trackforums' => 1));
        $useroff = $this->getDataGenerator()->create_user(array('trackforums' => 0));
        $course = $this->getDataGenerator()->create_course();
        $options = array('course' => $course->id, 'trackingtype' => FORUM_TRACKING_OFF); // Off.
        $forumoff = $this->getDataGenerator()->create_module('forum', $options);

        $options = array('course' => $course->id, 'trackingtype' => FORUM_TRACKING_FORCED); // On.
        $forumforce = $this->getDataGenerator()->create_module('forum', $options);

        $options = array('course' => $course->id, 'trackingtype' => FORUM_TRACKING_OPTIONAL); // Optional.
        $forumoptional = $this->getDataGenerator()->create_module('forum', $options);

        // Add discussions to the tracking off forum.
        $record = new \stdClass();
        $record->course = $course->id;
        $record->userid = $useron->id;
        $record->forum = $forumoff->id;
        $discussionoff = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($record);

        // Add discussions to the tracking forced forum.
        $record = new \stdClass();
        $record->course = $course->id;
        $record->userid = $useron->id;
        $record->forum = $forumforce->id;
        $discussionforce = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($record);

        // Add post to the tracking forced discussion.
        $record = new \stdClass();
        $record->course = $course->id;
        $record->userid = $useroff->id;
        $record->forum = $forumforce->id;
        $record->discussion = $discussionforce->id;
        $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_post($record);

        // Add discussions to the tracking optional forum.
        $record = new \stdClass();
        $record->course = $course->id;
        $record->userid = $useron->id;
        $record->forum = $forumoptional->id;
        $discussionoptional = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($record);

        // Allow force.
        $CFG->forum_allowforcedreadtracking = 1;

        $result = forum_tp_get_course_unread_posts($useron->id, $course->id);
        $this->assertEquals(2, count($result));
        $this->assertEquals(false, isset($result[$forumoff->id]));
        $this->assertEquals(true, isset($result[$forumforce->id]));
        $this->assertEquals(2, $result[$forumforce->id]->unread);
        $this->assertEquals(true, isset($result[$forumoptional->id]));
        $this->assertEquals(1, $result[$forumoptional->id]->unread);

        $result = forum_tp_get_course_unread_posts($useroff->id, $course->id);
        $this->assertEquals(1, count($result));
        $this->assertEquals(false, isset($result[$forumoff->id]));
        $this->assertEquals(true, isset($result[$forumforce->id]));
        $this->assertEquals(2, $result[$forumforce->id]->unread);
        $this->assertEquals(false, isset($result[$forumoptional->id]));

        // Don't allow force.
        $CFG->forum_allowforcedreadtracking = 0;

        $result = forum_tp_get_course_unread_posts($useron->id, $course->id);
        $this->assertEquals(2, count($result));
        $this->assertEquals(false, isset($result[$forumoff->id]));
        $this->assertEquals(true, isset($result[$forumforce->id]));
        $this->assertEquals(2, $result[$forumforce->id]->unread);
        $this->assertEquals(true, isset($result[$forumoptional->id]));
        $this->assertEquals(1, $result[$forumoptional->id]->unread);

        $result = forum_tp_get_course_unread_posts($useroff->id, $course->id);
        $this->assertEquals(0, count($result));
        $this->assertEquals(false, isset($result[$forumoff->id]));
        $this->assertEquals(false, isset($result[$forumforce->id]));
        $this->assertEquals(false, isset($result[$forumoptional->id]));

        // Stop tracking so we can test again.
        forum_tp_stop_tracking($forumforce->id, $useron->id);
        forum_tp_stop_tracking($forumoptional->id, $useron->id);
        forum_tp_stop_tracking($forumforce->id, $useroff->id);
        forum_tp_stop_tracking($forumoptional->id, $useroff->id);

        // Allow force.
        $CFG->forum_allowforcedreadtracking = 1;

        $result = forum_tp_get_course_unread_posts($useron->id, $course->id);
        $this->assertEquals(1, count($result));
        $this->assertEquals(false, isset($result[$forumoff->id]));
        $this->assertEquals(true, isset($result[$forumforce->id]));
        $this->assertEquals(2, $result[$forumforce->id]->unread);
        $this->assertEquals(false, isset($result[$forumoptional->id]));

        $result = forum_tp_get_course_unread_posts($useroff->id, $course->id);
        $this->assertEquals(1, count($result));
        $this->assertEquals(false, isset($result[$forumoff->id]));
        $this->assertEquals(true, isset($result[$forumforce->id]));
        $this->assertEquals(2, $result[$forumforce->id]->unread);
        $this->assertEquals(false, isset($result[$forumoptional->id]));

        // Don't allow force.
        $CFG->forum_allowforcedreadtracking = 0;

        $result = forum_tp_get_course_unread_posts($useron->id, $course->id);
        $this->assertEquals(0, count($result));
        $this->assertEquals(false, isset($result[$forumoff->id]));
        $this->assertEquals(false, isset($result[$forumforce->id]));
        $this->assertEquals(false, isset($result[$forumoptional->id]));

        $result = forum_tp_get_course_unread_posts($useroff->id, $course->id);
        $this->assertEquals(0, count($result));
        $this->assertEquals(false, isset($result[$forumoff->id]));
        $this->assertEquals(false, isset($result[$forumforce->id]));
        $this->assertEquals(false, isset($result[$forumoptional->id]));
    }

    /**
     * Test the logic in the forum_tp_get_course_unread_posts() function when private replies are present.
     *
     * @covers ::forum_tp_get_course_unread_posts
     */
    public function test_forum_tp_get_course_unread_posts_with_private_replies(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();

        // Create 3 students.
        $s1 = $generator->create_user(['trackforums' => 1]);
        $s2 = $generator->create_user(['trackforums' => 1]);
        $s3 = $generator->create_user(['trackforums' => 1]);
        // Editing teacher.
        $t1 = $generator->create_user(['trackforums' => 1]);
        // Non-editing teacher.
        $t2 = $generator->create_user(['trackforums' => 1]);

        // Create our course.
        $course = $generator->create_course();

        // Enrol editing and non-editing teachers.
        $generator->enrol_user($t1->id, $course->id, 'editingteacher');
        $generator->enrol_user($t2->id, $course->id, 'teacher');

        // Create forums.
        $forum1 = $generator->create_module('forum', ['course' => $course->id]);
        $forum2 = $generator->create_module('forum', ['course' => $course->id]);
        $forumgenerator = $generator->get_plugin_generator('mod_forum');

        // Prevent the non-editing teacher from reading private replies in forum 2.
        $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'teacher']);
        $forum2cm = get_coursemodule_from_instance('forum', $forum2->id);
        $forum2context = \context_module::instance($forum2cm->id);
        role_change_permission($teacherroleid, $forum2context, 'mod/forum:readprivatereplies', CAP_PREVENT);

        // Create discussion by s1.
        $discussiondata = (object)[
            'course' => $course->id,
            'forum' => $forum1->id,
            'userid' => $s1->id,
        ];
        $discussion1 = $forumgenerator->create_discussion($discussiondata);

        // Create discussion by s2.
        $discussiondata->userid = $s2->id;
        $discussion2 = $forumgenerator->create_discussion($discussiondata);

        // Create discussion by s3.
        $discussiondata->userid = $s3->id;
        $discussion3 = $forumgenerator->create_discussion($discussiondata);

        // Post a normal reply to s1's discussion in forum 1 as the editing teacher.
        $replydata = (object)[
            'course' => $course->id,
            'forum' => $forum1->id,
            'discussion' => $discussion1->id,
            'userid' => $t1->id,
        ];
        $forumgenerator->create_post($replydata);

        // Post a normal reply to s2's discussion as the editing teacher.
        $replydata->discussion = $discussion2->id;
        $forumgenerator->create_post($replydata);

        // Post a normal reply to s3's discussion as the editing teacher.
        $replydata->discussion = $discussion3->id;
        $forumgenerator->create_post($replydata);

        // Post a private reply to s1's discussion in forum 1 as the editing teacher.
        $replydata->discussion = $discussion1->id;
        $replydata->userid = $t1->id;
        $replydata->privatereplyto = $s1->id;
        $forumgenerator->create_post($replydata);
        // Post another private reply to s1 as the teacher.
        $forumgenerator->create_post($replydata);

        // Post a private reply to s2's discussion as the editing teacher.
        $replydata->discussion = $discussion2->id;
        $replydata->privatereplyto = $s2->id;
        $forumgenerator->create_post($replydata);

        // Create discussion by s1 in forum 2.
        $discussiondata->forum = $forum2->id;
        $discussiondata->userid = $s1->id;
        $discussion21 = $forumgenerator->create_discussion($discussiondata);

        // Post a private reply to s1's discussion in forum 2 as the editing teacher.
        $replydata->discussion = $discussion21->id;
        $replydata->privatereplyto = $s1->id;
        $forumgenerator->create_post($replydata);

        // Let's count!
        // S1 should see 8 unread posts 3 discussions posts + 2 private replies + 3 normal replies.
        $result = forum_tp_get_course_unread_posts($s1->id, $course->id);
        $unreadcounts = $result[$forum1->id];
        $this->assertEquals(8, $unreadcounts->unread);

        // S2 should see 7 unread posts 3 discussions posts + 1 private reply + 3 normal replies.
        $result = forum_tp_get_course_unread_posts($s2->id, $course->id);
        $unreadcounts = $result[$forum1->id];
        $this->assertEquals(7, $unreadcounts->unread);

        // S3 should see 6 unread posts 3 discussions posts + 3 normal replies. No private replies.
        $result = forum_tp_get_course_unread_posts($s3->id, $course->id);
        $unreadcounts = $result[$forum1->id];
        $this->assertEquals(6, $unreadcounts->unread);

        // The editing teacher should see 9 unread posts in forum 1: 3 discussions posts + 3 normal replies + 3 private replies.
        $result = forum_tp_get_course_unread_posts($t1->id, $course->id);
        $unreadcounts = $result[$forum1->id];
        $this->assertEquals(9, $unreadcounts->unread);

        // Same with the non-editing teacher, since they can read private replies by default.
        $result = forum_tp_get_course_unread_posts($t2->id, $course->id);
        $unreadcounts = $result[$forum1->id];
        $this->assertEquals(9, $unreadcounts->unread);

        // But for forum 2, the non-editing teacher should only see 1 unread which is s1's discussion post.
        $unreadcounts = $result[$forum2->id];
        $this->assertEquals(1, $unreadcounts->unread);
    }

    /**
     * Test the logic in the forum_tp_count_forum_unread_posts() function when private replies are present but without
     * separate group mode. This should yield the same results returned by forum_tp_get_course_unread_posts().
     *
     * @covers ::forum_tp_count_forum_unread_posts
     */
    public function test_forum_tp_count_forum_unread_posts_with_private_replies(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();

        // Create 3 students.
        $s1 = $generator->create_user(['username' => 's1', 'trackforums' => 1]);
        $s2 = $generator->create_user(['username' => 's2', 'trackforums' => 1]);
        $s3 = $generator->create_user(['username' => 's3', 'trackforums' => 1]);
        // Editing teacher.
        $t1 = $generator->create_user(['username' => 't1', 'trackforums' => 1]);
        // Non-editing teacher.
        $t2 = $generator->create_user(['username' => 't2', 'trackforums' => 1]);

        // Create our course.
        $course = $generator->create_course();

        // Enrol editing and non-editing teachers.
        $generator->enrol_user($t1->id, $course->id, 'editingteacher');
        $generator->enrol_user($t2->id, $course->id, 'teacher');

        // Create forums.
        $forum1 = $generator->create_module('forum', ['course' => $course->id]);
        $forum2 = $generator->create_module('forum', ['course' => $course->id]);
        $forumgenerator = $generator->get_plugin_generator('mod_forum');

        // Prevent the non-editing teacher from reading private replies in forum 2.
        $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'teacher']);
        $forum2cm = get_coursemodule_from_instance('forum', $forum2->id);
        $forum2context = \context_module::instance($forum2cm->id);
        role_change_permission($teacherroleid, $forum2context, 'mod/forum:readprivatereplies', CAP_PREVENT);

        // Create discussion by s1.
        $discussiondata = (object)[
            'course' => $course->id,
            'forum' => $forum1->id,
            'userid' => $s1->id,
        ];
        $discussion1 = $forumgenerator->create_discussion($discussiondata);

        // Create discussion by s2.
        $discussiondata->userid = $s2->id;
        $discussion2 = $forumgenerator->create_discussion($discussiondata);

        // Create discussion by s3.
        $discussiondata->userid = $s3->id;
        $discussion3 = $forumgenerator->create_discussion($discussiondata);

        // Post a normal reply to s1's discussion in forum 1 as the editing teacher.
        $replydata = (object)[
            'course' => $course->id,
            'forum' => $forum1->id,
            'discussion' => $discussion1->id,
            'userid' => $t1->id,
        ];
        $forumgenerator->create_post($replydata);

        // Post a normal reply to s2's discussion as the editing teacher.
        $replydata->discussion = $discussion2->id;
        $forumgenerator->create_post($replydata);

        // Post a normal reply to s3's discussion as the editing teacher.
        $replydata->discussion = $discussion3->id;
        $forumgenerator->create_post($replydata);

        // Post a private reply to s1's discussion in forum 1 as the editing teacher.
        $replydata->discussion = $discussion1->id;
        $replydata->userid = $t1->id;
        $replydata->privatereplyto = $s1->id;
        $forumgenerator->create_post($replydata);
        // Post another private reply to s1 as the teacher.
        $forumgenerator->create_post($replydata);

        // Post a private reply to s2's discussion as the editing teacher.
        $replydata->discussion = $discussion2->id;
        $replydata->privatereplyto = $s2->id;
        $forumgenerator->create_post($replydata);

        // Create discussion by s1 in forum 2.
        $discussiondata->forum = $forum2->id;
        $discussiondata->userid = $s1->id;
        $discussion11 = $forumgenerator->create_discussion($discussiondata);

        // Post a private reply to s1's discussion in forum 2 as the editing teacher.
        $replydata->discussion = $discussion11->id;
        $replydata->privatereplyto = $s1->id;
        $forumgenerator->create_post($replydata);

        // Let's count!
        // S1 should see 8 unread posts 3 discussions posts + 2 private replies + 3 normal replies.
        $this->setUser($s1);
        $forum1cm = get_coursemodule_from_instance('forum', $forum1->id);
        $result = forum_tp_count_forum_unread_posts($forum1cm, $course, true);
        $this->assertEquals(8, $result);

        // S2 should see 7 unread posts 3 discussions posts + 1 private reply + 3 normal replies.
        $this->setUser($s2);
        $result = forum_tp_count_forum_unread_posts($forum1cm, $course, true);
        $this->assertEquals(7, $result);

        // S3 should see 6 unread posts 3 discussions posts + 3 normal replies. No private replies.
        $this->setUser($s3);
        $result = forum_tp_count_forum_unread_posts($forum1cm, $course, true);
        $this->assertEquals(6, $result);

        // The editing teacher should see 9 unread posts in forum 1: 3 discussions posts + 3 normal replies + 3 private replies.
        $this->setUser($t1);
        $result = forum_tp_count_forum_unread_posts($forum1cm, $course, true);
        $this->assertEquals(9, $result);

        // Same with the non-editing teacher, since they can read private replies by default.
        $this->setUser($t2);
        $result = forum_tp_count_forum_unread_posts($forum1cm, $course, true);
        $this->assertEquals(9, $result);

        // But for forum 2, the non-editing teacher should only see 1 unread which is s1's discussion post.
        $result = forum_tp_count_forum_unread_posts($forum2cm, $course);
        $this->assertEquals(1, $result);
    }

    /**
     * Test the logic in the forum_tp_count_forum_unread_posts() function when private replies are present and group modes are set.
     *
     * @covers ::forum_tp_count_forum_unread_posts
     */
    public function test_forum_tp_count_forum_unread_posts_with_private_replies_and_separate_groups(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();

        // Create 3 students.
        $s1 = $generator->create_user(['username' => 's1', 'trackforums' => 1]);
        $s2 = $generator->create_user(['username' => 's2', 'trackforums' => 1]);
        // Editing teacher.
        $t1 = $generator->create_user(['username' => 't1', 'trackforums' => 1]);

        // Create our course.
        $course = $generator->create_course();

        // Enrol students, editing and non-editing teachers.
        $generator->enrol_user($s1->id, $course->id, 'student');
        $generator->enrol_user($s2->id, $course->id, 'student');
        $generator->enrol_user($t1->id, $course->id, 'editingteacher');

        // Create groups.
        $g1 = $generator->create_group(['courseid' => $course->id]);
        $g2 = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $g1->id, 'userid' => $s1->id]);
        $generator->create_group_member(['groupid' => $g2->id, 'userid' => $s2->id]);

        // Create forums.
        $forum1 = $generator->create_module('forum', ['course' => $course->id, 'groupmode' => SEPARATEGROUPS]);
        $forum2 = $generator->create_module('forum', ['course' => $course->id, 'groupmode' => VISIBLEGROUPS]);
        $forumgenerator = $generator->get_plugin_generator('mod_forum');

        // Create discussion by s1.
        $discussiondata = (object)[
            'course' => $course->id,
            'forum' => $forum1->id,
            'userid' => $s1->id,
            'groupid' => $g1->id,
        ];
        $discussion1 = $forumgenerator->create_discussion($discussiondata);

        // Create discussion by s2.
        $discussiondata->userid = $s2->id;
        $discussiondata->groupid = $g2->id;
        $discussion2 = $forumgenerator->create_discussion($discussiondata);

        // Post a normal reply to s1's discussion in forum 1 as the editing teacher.
        $replydata = (object)[
            'course' => $course->id,
            'forum' => $forum1->id,
            'discussion' => $discussion1->id,
            'userid' => $t1->id,
        ];
        $forumgenerator->create_post($replydata);

        // Post a normal reply to s2's discussion as the editing teacher.
        $replydata->discussion = $discussion2->id;
        $forumgenerator->create_post($replydata);

        // Post a private reply to s1's discussion in forum 1 as the editing teacher.
        $replydata->discussion = $discussion1->id;
        $replydata->userid = $t1->id;
        $replydata->privatereplyto = $s1->id;
        $forumgenerator->create_post($replydata);
        // Post another private reply to s1 as the teacher.
        $forumgenerator->create_post($replydata);

        // Post a private reply to s2's discussion as the editing teacher.
        $replydata->discussion = $discussion2->id;
        $replydata->privatereplyto = $s2->id;
        $forumgenerator->create_post($replydata);

        // Create discussion by s1 in forum 2.
        $discussiondata->forum = $forum2->id;
        $discussiondata->userid = $s1->id;
        $discussiondata->groupid = $g1->id;
        $discussion21 = $forumgenerator->create_discussion($discussiondata);

        // Post a private reply to s1's discussion in forum 2 as the editing teacher.
        $replydata->discussion = $discussion21->id;
        $replydata->privatereplyto = $s1->id;
        $forumgenerator->create_post($replydata);

        // Let's count!
        // S1 should see 4 unread posts in forum 1 (1 discussions post + 2 private replies + 1 normal reply).
        $this->setUser($s1);
        $forum1cm = get_coursemodule_from_instance('forum', $forum1->id);
        $result = forum_tp_count_forum_unread_posts($forum1cm, $course, true);
        $this->assertEquals(4, $result);

        // S2 should see 3 unread posts in forum 1 (1 discussions post + 1 private reply + 1 normal reply).
        $this->setUser($s2);
        $result = forum_tp_count_forum_unread_posts($forum1cm, $course, true);
        $this->assertEquals(3, $result);

        // S2 should see 1 unread posts in forum 2 (visible groups, 1 discussion post from s1).
        $forum2cm = get_coursemodule_from_instance('forum', $forum2->id);
        $result = forum_tp_count_forum_unread_posts($forum2cm, $course, true);
        $this->assertEquals(1, $result);

        // The editing teacher should still see 7 unread posts (2 discussions posts + 2 normal replies + 3 private replies)
        // in forum 1 since they have the capability to view all groups by default.
        $this->setUser($t1);
        $result = forum_tp_count_forum_unread_posts($forum1cm, $course, true);
        $this->assertEquals(7, $result);
    }

    /**
     * Test subscription using automatic subscription on create.
     */
    public function test_forum_auto_subscribe_on_create(): void {
        global $CFG;

        $this->resetAfterTest();

        $usercount = 5;
        $course = $this->getDataGenerator()->create_course();
        $users = array();

        for ($i = 0; $i < $usercount; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $users[] = $user;
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
        }

        $options = array('course' => $course->id, 'forcesubscribe' => FORUM_INITIALSUBSCRIBE); // Automatic Subscription.
        $forum = $this->getDataGenerator()->create_module('forum', $options);

        $result = \mod_forum\subscriptions::fetch_subscribed_users($forum);
        $this->assertEquals($usercount, count($result));
        foreach ($users as $user) {
            $this->assertTrue(\mod_forum\subscriptions::is_subscribed($user->id, $forum));
        }
    }

    /**
     * Test subscription using forced subscription on create.
     */
    public function test_forum_forced_subscribe_on_create(): void {
        global $CFG;

        $this->resetAfterTest();

        $usercount = 5;
        $course = $this->getDataGenerator()->create_course();
        $users = array();

        for ($i = 0; $i < $usercount; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $users[] = $user;
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
        }

        $options = array('course' => $course->id, 'forcesubscribe' => FORUM_FORCESUBSCRIBE); // Forced subscription.
        $forum = $this->getDataGenerator()->create_module('forum', $options);

        $result = \mod_forum\subscriptions::fetch_subscribed_users($forum);
        $this->assertEquals($usercount, count($result));
        foreach ($users as $user) {
            $this->assertTrue(\mod_forum\subscriptions::is_subscribed($user->id, $forum));
        }
    }

    /**
     * Test subscription using optional subscription on create.
     */
    public function test_forum_optional_subscribe_on_create(): void {
        global $CFG;

        $this->resetAfterTest();

        $usercount = 5;
        $course = $this->getDataGenerator()->create_course();
        $users = array();

        for ($i = 0; $i < $usercount; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $users[] = $user;
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
        }

        $options = array('course' => $course->id, 'forcesubscribe' => FORUM_CHOOSESUBSCRIBE); // Subscription optional.
        $forum = $this->getDataGenerator()->create_module('forum', $options);

        $result = \mod_forum\subscriptions::fetch_subscribed_users($forum);
        // No subscriptions by default.
        $this->assertEquals(0, count($result));
        foreach ($users as $user) {
            $this->assertFalse(\mod_forum\subscriptions::is_subscribed($user->id, $forum));
        }
    }

    /**
     * Test subscription using disallow subscription on create.
     */
    public function test_forum_disallow_subscribe_on_create(): void {
        global $CFG;

        $this->resetAfterTest();

        $usercount = 5;
        $course = $this->getDataGenerator()->create_course();
        $users = array();

        for ($i = 0; $i < $usercount; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $users[] = $user;
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
        }

        $options = array('course' => $course->id, 'forcesubscribe' => FORUM_DISALLOWSUBSCRIBE); // Subscription prevented.
        $forum = $this->getDataGenerator()->create_module('forum', $options);

        $result = \mod_forum\subscriptions::fetch_subscribed_users($forum);
        // No subscriptions by default.
        $this->assertEquals(0, count($result));
        foreach ($users as $user) {
            $this->assertFalse(\mod_forum\subscriptions::is_subscribed($user->id, $forum));
        }
    }

    /**
     * Test that context fetching returns the appropriate context.
     */
    public function test_forum_get_context(): void {
        global $DB, $PAGE;

        $this->resetAfterTest();

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);

        $options = array('course' => $course->id, 'forcesubscribe' => FORUM_CHOOSESUBSCRIBE);
        $forum = $this->getDataGenerator()->create_module('forum', $options);
        $forumcm = get_coursemodule_from_instance('forum', $forum->id);
        $forumcontext = \context_module::instance($forumcm->id);

        // First check that specifying the context results in the correct context being returned.
        // Do this before we set up the page object and we should return from the coursemodule record.
        // There should be no DB queries here because the context type was correct.
        $startcount = $DB->perf_get_reads();
        $result = forum_get_context($forum->id, $forumcontext);
        $aftercount = $DB->perf_get_reads();
        $this->assertEquals($forumcontext, $result);
        $this->assertEquals(0, $aftercount - $startcount);

        // And a context which is not the correct type.
        // This tests will result in a DB query to fetch the course_module.
        $startcount = $DB->perf_get_reads();
        $result = forum_get_context($forum->id, $coursecontext);
        $aftercount = $DB->perf_get_reads();
        $this->assertEquals($forumcontext, $result);
        $this->assertEquals(1, $aftercount - $startcount);

        // Now do not specify a context at all.
        // This tests will result in a DB query to fetch the course_module.
        $startcount = $DB->perf_get_reads();
        $result = forum_get_context($forum->id);
        $aftercount = $DB->perf_get_reads();
        $this->assertEquals($forumcontext, $result);
        $this->assertEquals(1, $aftercount - $startcount);

        // Set up the default page event to use the forum.
        $PAGE = new \moodle_page();
        $PAGE->set_context($forumcontext);
        $PAGE->set_cm($forumcm, $course, $forum);

        // Now specify a context which is not a context_module.
        // There should be no DB queries here because we use the PAGE.
        $startcount = $DB->perf_get_reads();
        $result = forum_get_context($forum->id, $coursecontext);
        $aftercount = $DB->perf_get_reads();
        $this->assertEquals($forumcontext, $result);
        $this->assertEquals(0, $aftercount - $startcount);

        // Now do not specify a context at all.
        // There should be no DB queries here because we use the PAGE.
        $startcount = $DB->perf_get_reads();
        $result = forum_get_context($forum->id);
        $aftercount = $DB->perf_get_reads();
        $this->assertEquals($forumcontext, $result);
        $this->assertEquals(0, $aftercount - $startcount);

        // Now specify the page context of the course instead..
        $PAGE = new \moodle_page();
        $PAGE->set_context($coursecontext);

        // Now specify a context which is not a context_module.
        // This tests will result in a DB query to fetch the course_module.
        $startcount = $DB->perf_get_reads();
        $result = forum_get_context($forum->id, $coursecontext);
        $aftercount = $DB->perf_get_reads();
        $this->assertEquals($forumcontext, $result);
        $this->assertEquals(1, $aftercount - $startcount);

        // Now do not specify a context at all.
        // This tests will result in a DB query to fetch the course_module.
        $startcount = $DB->perf_get_reads();
        $result = forum_get_context($forum->id);
        $aftercount = $DB->perf_get_reads();
        $this->assertEquals($forumcontext, $result);
        $this->assertEquals(1, $aftercount - $startcount);
    }

    /**
     * Test getting the neighbour threads of a discussion.
     */
    public function test_forum_get_neighbours(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        $timenow = time();
        $timenext = $timenow;

        // Setup test data.
        $forumgen = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $forum = $this->getDataGenerator()->create_module('forum', array('course' => $course->id));
        $cm = get_coursemodule_from_instance('forum', $forum->id);
        $context = \context_module::instance($cm->id);

        $record = new \stdClass();
        $record->course = $course->id;
        $record->userid = $user->id;
        $record->forum = $forum->id;
        $record->timemodified = time();
        $disc1 = $forumgen->create_discussion($record);
        $record->timemodified++;
        $disc2 = $forumgen->create_discussion($record);
        $record->timemodified++;
        $disc3 = $forumgen->create_discussion($record);
        $record->timemodified++;
        $disc4 = $forumgen->create_discussion($record);
        $record->timemodified++;
        $disc5 = $forumgen->create_discussion($record);

        // Getting the neighbours.
        $neighbours = forum_get_discussion_neighbours($cm, $disc1, $forum);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc2->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc2, $forum);
        $this->assertEquals($disc1->id, $neighbours['prev']->id);
        $this->assertEquals($disc3->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc3, $forum);
        $this->assertEquals($disc2->id, $neighbours['prev']->id);
        $this->assertEquals($disc4->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc4, $forum);
        $this->assertEquals($disc3->id, $neighbours['prev']->id);
        $this->assertEquals($disc5->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc5, $forum);
        $this->assertEquals($disc4->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Post in some discussions. We manually update the discussion record because
        // the data generator plays with timemodified in a way that would break this test.
        $record->timemodified++;
        $disc1->timemodified = $record->timemodified;
        $DB->update_record('forum_discussions', $disc1);

        $neighbours = forum_get_discussion_neighbours($cm, $disc5, $forum);
        $this->assertEquals($disc4->id, $neighbours['prev']->id);
        $this->assertEquals($disc1->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc2, $forum);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc3->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc1, $forum);
        $this->assertEquals($disc5->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // After some discussions were created.
        $record->timemodified++;
        $disc6 = $forumgen->create_discussion($record);
        $neighbours = forum_get_discussion_neighbours($cm, $disc6, $forum);
        $this->assertEquals($disc1->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        $record->timemodified++;
        $disc7 = $forumgen->create_discussion($record);
        $neighbours = forum_get_discussion_neighbours($cm, $disc7, $forum);
        $this->assertEquals($disc6->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Adding timed discussions.
        $CFG->forum_enabletimedposts = true;
        $now = $record->timemodified;
        $past = $now - 600;
        $future = $now + 600;

        $record = new \stdClass();
        $record->course = $course->id;
        $record->userid = $user->id;
        $record->forum = $forum->id;
        $record->timestart = $past;
        $record->timeend = $future;
        $record->timemodified = $now;
        $record->timemodified++;
        $disc8 = $forumgen->create_discussion($record);
        $record->timemodified++;
        $record->timestart = $future;
        $record->timeend = 0;
        $disc9 = $forumgen->create_discussion($record);
        $record->timemodified++;
        $record->timestart = 0;
        $record->timeend = 0;
        $disc10 = $forumgen->create_discussion($record);
        $record->timemodified++;
        $record->timestart = 0;
        $record->timeend = $past;
        $disc11 = $forumgen->create_discussion($record);
        $record->timemodified++;
        $record->timestart = $past;
        $record->timeend = $future;
        $disc12 = $forumgen->create_discussion($record);
        $record->timemodified++;
        $record->timestart = $future + 1; // Should be last post for those that can see it.
        $record->timeend = 0;
        $disc13 = $forumgen->create_discussion($record);

        // Admin user ignores the timed settings of discussions.
        // Post ordering taking into account timestart:
        //  8 = t
        // 10 = t+3
        // 11 = t+4
        // 12 = t+5
        //  9 = t+60
        // 13 = t+61.
        $this->setAdminUser();
        $neighbours = forum_get_discussion_neighbours($cm, $disc8, $forum);
        $this->assertEquals($disc7->id, $neighbours['prev']->id);
        $this->assertEquals($disc10->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc9, $forum);
        $this->assertEquals($disc12->id, $neighbours['prev']->id);
        $this->assertEquals($disc13->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc10, $forum);
        $this->assertEquals($disc8->id, $neighbours['prev']->id);
        $this->assertEquals($disc11->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc11, $forum);
        $this->assertEquals($disc10->id, $neighbours['prev']->id);
        $this->assertEquals($disc12->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc12, $forum);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEquals($disc9->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc13, $forum);
        $this->assertEquals($disc9->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Normal user can see their own timed discussions.
        $this->setUser($user);
        $neighbours = forum_get_discussion_neighbours($cm, $disc8, $forum);
        $this->assertEquals($disc7->id, $neighbours['prev']->id);
        $this->assertEquals($disc10->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc9, $forum);
        $this->assertEquals($disc12->id, $neighbours['prev']->id);
        $this->assertEquals($disc13->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc10, $forum);
        $this->assertEquals($disc8->id, $neighbours['prev']->id);
        $this->assertEquals($disc11->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc11, $forum);
        $this->assertEquals($disc10->id, $neighbours['prev']->id);
        $this->assertEquals($disc12->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc12, $forum);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEquals($disc9->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc13, $forum);
        $this->assertEquals($disc9->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Normal user does not ignore timed settings.
        $this->setUser($user2);
        $neighbours = forum_get_discussion_neighbours($cm, $disc8, $forum);
        $this->assertEquals($disc7->id, $neighbours['prev']->id);
        $this->assertEquals($disc10->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc10, $forum);
        $this->assertEquals($disc8->id, $neighbours['prev']->id);
        $this->assertEquals($disc12->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc12, $forum);
        $this->assertEquals($disc10->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Reset to normal mode.
        $CFG->forum_enabletimedposts = false;
        $this->setAdminUser();

        // Two discussions with identical timemodified will sort by id.
        $record->timemodified += 25;
        $DB->update_record('forum_discussions', (object) array('id' => $disc3->id, 'timemodified' => $record->timemodified));
        $DB->update_record('forum_discussions', (object) array('id' => $disc2->id, 'timemodified' => $record->timemodified));
        $DB->update_record('forum_discussions', (object) array('id' => $disc12->id, 'timemodified' => $record->timemodified - 5));
        $disc2 = $DB->get_record('forum_discussions', array('id' => $disc2->id));
        $disc3 = $DB->get_record('forum_discussions', array('id' => $disc3->id));

        $neighbours = forum_get_discussion_neighbours($cm, $disc3, $forum);
        $this->assertEquals($disc2->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        $neighbours = forum_get_discussion_neighbours($cm, $disc2, $forum);
        $this->assertEquals($disc12->id, $neighbours['prev']->id);
        $this->assertEquals($disc3->id, $neighbours['next']->id);

        // Set timemodified to not be identical.
        $DB->update_record('forum_discussions', (object) array('id' => $disc2->id, 'timemodified' => $record->timemodified - 1));

        // Test pinned posts behave correctly.
        $disc8->pinned = FORUM_DISCUSSION_PINNED;
        $DB->update_record('forum_discussions', (object) array('id' => $disc8->id, 'pinned' => $disc8->pinned));
        $neighbours = forum_get_discussion_neighbours($cm, $disc8, $forum);
        $this->assertEquals($disc3->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        $neighbours = forum_get_discussion_neighbours($cm, $disc3, $forum);
        $this->assertEquals($disc2->id, $neighbours['prev']->id);
        $this->assertEquals($disc8->id, $neighbours['next']->id);

        // Test 3 pinned posts.
        $disc6->pinned = FORUM_DISCUSSION_PINNED;
        $DB->update_record('forum_discussions', (object) array('id' => $disc6->id, 'pinned' => $disc6->pinned));
        $disc4->pinned = FORUM_DISCUSSION_PINNED;
        $DB->update_record('forum_discussions', (object) array('id' => $disc4->id, 'pinned' => $disc4->pinned));

        $neighbours = forum_get_discussion_neighbours($cm, $disc6, $forum);
        $this->assertEquals($disc4->id, $neighbours['prev']->id);
        $this->assertEquals($disc8->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc4, $forum);
        $this->assertEquals($disc3->id, $neighbours['prev']->id);
        $this->assertEquals($disc6->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc8, $forum);
        $this->assertEquals($disc6->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);
    }

    /**
     * Test getting the neighbour threads of a blog-like forum.
     */
    public function test_forum_get_neighbours_blog(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        $timenow = time();
        $timenext = $timenow;

        // Setup test data.
        $forumgen = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $forum = $this->getDataGenerator()->create_module('forum', array('course' => $course->id, 'type' => 'blog'));
        $cm = get_coursemodule_from_instance('forum', $forum->id);
        $context = \context_module::instance($cm->id);

        $record = new \stdClass();
        $record->course = $course->id;
        $record->userid = $user->id;
        $record->forum = $forum->id;
        $record->timemodified = time();
        $disc1 = $forumgen->create_discussion($record);
        $record->timemodified++;
        $disc2 = $forumgen->create_discussion($record);
        $record->timemodified++;
        $disc3 = $forumgen->create_discussion($record);
        $record->timemodified++;
        $disc4 = $forumgen->create_discussion($record);
        $record->timemodified++;
        $disc5 = $forumgen->create_discussion($record);

        // Getting the neighbours.
        $neighbours = forum_get_discussion_neighbours($cm, $disc1, $forum);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc2->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc2, $forum);
        $this->assertEquals($disc1->id, $neighbours['prev']->id);
        $this->assertEquals($disc3->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc3, $forum);
        $this->assertEquals($disc2->id, $neighbours['prev']->id);
        $this->assertEquals($disc4->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc4, $forum);
        $this->assertEquals($disc3->id, $neighbours['prev']->id);
        $this->assertEquals($disc5->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc5, $forum);
        $this->assertEquals($disc4->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Make sure that the thread's timemodified does not affect the order.
        $record->timemodified++;
        $disc1->timemodified = $record->timemodified;
        $DB->update_record('forum_discussions', $disc1);

        $neighbours = forum_get_discussion_neighbours($cm, $disc1, $forum);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc2->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc2, $forum);
        $this->assertEquals($disc1->id, $neighbours['prev']->id);
        $this->assertEquals($disc3->id, $neighbours['next']->id);

        // Add another blog post.
        $record->timemodified++;
        $disc6 = $forumgen->create_discussion($record);
        $neighbours = forum_get_discussion_neighbours($cm, $disc6, $forum);
        $this->assertEquals($disc5->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        $record->timemodified++;
        $disc7 = $forumgen->create_discussion($record);
        $neighbours = forum_get_discussion_neighbours($cm, $disc7, $forum);
        $this->assertEquals($disc6->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Adding timed discussions.
        $CFG->forum_enabletimedposts = true;
        $now = $record->timemodified;
        $past = $now - 600;
        $future = $now + 600;

        $record = new \stdClass();
        $record->course = $course->id;
        $record->userid = $user->id;
        $record->forum = $forum->id;
        $record->timestart = $past;
        $record->timeend = $future;
        $record->timemodified = $now;
        $record->timemodified++;
        $disc8 = $forumgen->create_discussion($record);
        $record->timemodified++;
        $record->timestart = $future;
        $record->timeend = 0;
        $disc9 = $forumgen->create_discussion($record);
        $record->timemodified++;
        $record->timestart = 0;
        $record->timeend = 0;
        $disc10 = $forumgen->create_discussion($record);
        $record->timemodified++;
        $record->timestart = 0;
        $record->timeend = $past;
        $disc11 = $forumgen->create_discussion($record);
        $record->timemodified++;
        $record->timestart = $past;
        $record->timeend = $future;
        $disc12 = $forumgen->create_discussion($record);

        // Admin user ignores the timed settings of discussions.
        $this->setAdminUser();
        $neighbours = forum_get_discussion_neighbours($cm, $disc8, $forum);
        $this->assertEquals($disc7->id, $neighbours['prev']->id);
        $this->assertEquals($disc9->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc9, $forum);
        $this->assertEquals($disc8->id, $neighbours['prev']->id);
        $this->assertEquals($disc10->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc10, $forum);
        $this->assertEquals($disc9->id, $neighbours['prev']->id);
        $this->assertEquals($disc11->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc11, $forum);
        $this->assertEquals($disc10->id, $neighbours['prev']->id);
        $this->assertEquals($disc12->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc12, $forum);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Normal user can see their own timed discussions.
        $this->setUser($user);
        $neighbours = forum_get_discussion_neighbours($cm, $disc8, $forum);
        $this->assertEquals($disc7->id, $neighbours['prev']->id);
        $this->assertEquals($disc9->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc9, $forum);
        $this->assertEquals($disc8->id, $neighbours['prev']->id);
        $this->assertEquals($disc10->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc10, $forum);
        $this->assertEquals($disc9->id, $neighbours['prev']->id);
        $this->assertEquals($disc11->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc11, $forum);
        $this->assertEquals($disc10->id, $neighbours['prev']->id);
        $this->assertEquals($disc12->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc12, $forum);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Normal user does not ignore timed settings.
        $this->setUser($user2);
        $neighbours = forum_get_discussion_neighbours($cm, $disc8, $forum);
        $this->assertEquals($disc7->id, $neighbours['prev']->id);
        $this->assertEquals($disc10->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc10, $forum);
        $this->assertEquals($disc8->id, $neighbours['prev']->id);
        $this->assertEquals($disc12->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc12, $forum);
        $this->assertEquals($disc10->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Reset to normal mode.
        $CFG->forum_enabletimedposts = false;
        $this->setAdminUser();

        $record->timemodified++;
        // Two blog posts with identical creation time will sort by id.
        $DB->update_record('forum_posts', (object) array('id' => $disc2->firstpost, 'created' => $record->timemodified));
        $DB->update_record('forum_posts', (object) array('id' => $disc3->firstpost, 'created' => $record->timemodified));

        $neighbours = forum_get_discussion_neighbours($cm, $disc2, $forum);
        $this->assertEquals($disc12->id, $neighbours['prev']->id);
        $this->assertEquals($disc3->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm, $disc3, $forum);
        $this->assertEquals($disc2->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);
    }

    /**
     * Test getting the neighbour threads of a discussion.
     */
    public function test_forum_get_neighbours_with_groups(): void {
        $this->resetAfterTest();

        $timenow = time();
        $timenext = $timenow;

        // Setup test data.
        $forumgen = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $course = $this->getDataGenerator()->create_course();
        $group1 = $this->getDataGenerator()->create_group(array('courseid' => $course->id));
        $group2 = $this->getDataGenerator()->create_group(array('courseid' => $course->id));
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user1->id, $course->id);
        $this->getDataGenerator()->enrol_user($user2->id, $course->id);
        $this->getDataGenerator()->create_group_member(array('userid' => $user1->id, 'groupid' => $group1->id));

        $forum1 = $this->getDataGenerator()->create_module('forum', array('course' => $course->id, 'groupmode' => VISIBLEGROUPS));
        $forum2 = $this->getDataGenerator()->create_module('forum', array('course' => $course->id, 'groupmode' => SEPARATEGROUPS));
        $cm1 = get_coursemodule_from_instance('forum', $forum1->id);
        $cm2 = get_coursemodule_from_instance('forum', $forum2->id);
        $context1 = \context_module::instance($cm1->id);
        $context2 = \context_module::instance($cm2->id);

        // Creating discussions in both forums.
        $record = new \stdClass();
        $record->course = $course->id;
        $record->userid = $user1->id;
        $record->forum = $forum1->id;
        $record->groupid = $group1->id;
        $record->timemodified = time();
        $disc11 = $forumgen->create_discussion($record);
        $record->forum = $forum2->id;
        $record->timemodified++;
        $disc21 = $forumgen->create_discussion($record);

        $record->timemodified++;
        $record->userid = $user2->id;
        $record->forum = $forum1->id;
        $record->groupid = $group2->id;
        $disc12 = $forumgen->create_discussion($record);
        $record->forum = $forum2->id;
        $disc22 = $forumgen->create_discussion($record);

        $record->timemodified++;
        $record->userid = $user1->id;
        $record->forum = $forum1->id;
        $record->groupid = null;
        $disc13 = $forumgen->create_discussion($record);
        $record->forum = $forum2->id;
        $disc23 = $forumgen->create_discussion($record);

        $record->timemodified++;
        $record->userid = $user2->id;
        $record->forum = $forum1->id;
        $record->groupid = $group2->id;
        $disc14 = $forumgen->create_discussion($record);
        $record->forum = $forum2->id;
        $disc24 = $forumgen->create_discussion($record);

        $record->timemodified++;
        $record->userid = $user1->id;
        $record->forum = $forum1->id;
        $record->groupid = $group1->id;
        $disc15 = $forumgen->create_discussion($record);
        $record->forum = $forum2->id;
        $disc25 = $forumgen->create_discussion($record);

        // Admin user can see all groups.
        $this->setAdminUser();
        $neighbours = forum_get_discussion_neighbours($cm1, $disc11, $forum1);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc12->id, $neighbours['next']->id);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc21, $forum2);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc22->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm1, $disc12, $forum1);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEquals($disc13->id, $neighbours['next']->id);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc22, $forum2);
        $this->assertEquals($disc21->id, $neighbours['prev']->id);
        $this->assertEquals($disc23->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm1, $disc13, $forum1);
        $this->assertEquals($disc12->id, $neighbours['prev']->id);
        $this->assertEquals($disc14->id, $neighbours['next']->id);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc23, $forum2);
        $this->assertEquals($disc22->id, $neighbours['prev']->id);
        $this->assertEquals($disc24->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm1, $disc14, $forum1);
        $this->assertEquals($disc13->id, $neighbours['prev']->id);
        $this->assertEquals($disc15->id, $neighbours['next']->id);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc24, $forum2);
        $this->assertEquals($disc23->id, $neighbours['prev']->id);
        $this->assertEquals($disc25->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm1, $disc15, $forum1);
        $this->assertEquals($disc14->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc25, $forum2);
        $this->assertEquals($disc24->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Admin user is only viewing group 1.
        $_POST['group'] = $group1->id;
        $this->assertEquals($group1->id, groups_get_activity_group($cm1, true));
        $this->assertEquals($group1->id, groups_get_activity_group($cm2, true));

        $neighbours = forum_get_discussion_neighbours($cm1, $disc11, $forum1);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc13->id, $neighbours['next']->id);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc21, $forum2);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc23->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm1, $disc13, $forum1);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEquals($disc15->id, $neighbours['next']->id);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc23, $forum2);
        $this->assertEquals($disc21->id, $neighbours['prev']->id);
        $this->assertEquals($disc25->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm1, $disc15, $forum1);
        $this->assertEquals($disc13->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc25, $forum2);
        $this->assertEquals($disc23->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Normal user viewing non-grouped posts (this is only possible in visible groups).
        $this->setUser($user1);
        $_POST['group'] = 0;
        $this->assertEquals(0, groups_get_activity_group($cm1, true));

        // They can see anything in visible groups.
        $neighbours = forum_get_discussion_neighbours($cm1, $disc12, $forum1);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEquals($disc13->id, $neighbours['next']->id);
        $neighbours = forum_get_discussion_neighbours($cm1, $disc13, $forum1);
        $this->assertEquals($disc12->id, $neighbours['prev']->id);
        $this->assertEquals($disc14->id, $neighbours['next']->id);

        // Normal user, orphan of groups, can only see non-grouped posts in separate groups.
        $this->setUser($user2);
        $_POST['group'] = 0;
        $this->assertEquals(0, groups_get_activity_group($cm2, true));

        $neighbours = forum_get_discussion_neighbours($cm2, $disc23, $forum2);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEmpty($neighbours['next']);

        $neighbours = forum_get_discussion_neighbours($cm2, $disc22, $forum2);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc23->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm2, $disc24, $forum2);
        $this->assertEquals($disc23->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Switching to viewing group 1.
        $this->setUser($user1);
        $_POST['group'] = $group1->id;
        $this->assertEquals($group1->id, groups_get_activity_group($cm1, true));
        $this->assertEquals($group1->id, groups_get_activity_group($cm2, true));

        // They can see non-grouped or same group.
        $neighbours = forum_get_discussion_neighbours($cm1, $disc11, $forum1);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc13->id, $neighbours['next']->id);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc21, $forum2);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc23->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm1, $disc13, $forum1);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEquals($disc15->id, $neighbours['next']->id);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc23, $forum2);
        $this->assertEquals($disc21->id, $neighbours['prev']->id);
        $this->assertEquals($disc25->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm1, $disc15, $forum1);
        $this->assertEquals($disc13->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc25, $forum2);
        $this->assertEquals($disc23->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Querying the neighbours of a discussion passing the wrong CM.
        $this->expectException('coding_exception');
        forum_get_discussion_neighbours($cm2, $disc11, $forum2);
    }

    /**
     * Test getting the neighbour threads of a blog-like forum with groups involved.
     */
    public function test_forum_get_neighbours_with_groups_blog(): void {
        $this->resetAfterTest();

        $timenow = time();
        $timenext = $timenow;

        // Setup test data.
        $forumgen = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $course = $this->getDataGenerator()->create_course();
        $group1 = $this->getDataGenerator()->create_group(array('courseid' => $course->id));
        $group2 = $this->getDataGenerator()->create_group(array('courseid' => $course->id));
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user1->id, $course->id);
        $this->getDataGenerator()->enrol_user($user2->id, $course->id);
        $this->getDataGenerator()->create_group_member(array('userid' => $user1->id, 'groupid' => $group1->id));

        $forum1 = $this->getDataGenerator()->create_module('forum', array('course' => $course->id, 'type' => 'blog',
                'groupmode' => VISIBLEGROUPS));
        $forum2 = $this->getDataGenerator()->create_module('forum', array('course' => $course->id, 'type' => 'blog',
                'groupmode' => SEPARATEGROUPS));
        $cm1 = get_coursemodule_from_instance('forum', $forum1->id);
        $cm2 = get_coursemodule_from_instance('forum', $forum2->id);
        $context1 = \context_module::instance($cm1->id);
        $context2 = \context_module::instance($cm2->id);

        // Creating blog posts in both forums.
        $record = new \stdClass();
        $record->course = $course->id;
        $record->userid = $user1->id;
        $record->forum = $forum1->id;
        $record->groupid = $group1->id;
        $record->timemodified = time();
        $disc11 = $forumgen->create_discussion($record);
        $record->timenow = $timenext++;
        $record->forum = $forum2->id;
        $record->timemodified++;
        $disc21 = $forumgen->create_discussion($record);

        $record->timemodified++;
        $record->userid = $user2->id;
        $record->forum = $forum1->id;
        $record->groupid = $group2->id;
        $disc12 = $forumgen->create_discussion($record);
        $record->forum = $forum2->id;
        $disc22 = $forumgen->create_discussion($record);

        $record->timemodified++;
        $record->userid = $user1->id;
        $record->forum = $forum1->id;
        $record->groupid = null;
        $disc13 = $forumgen->create_discussion($record);
        $record->forum = $forum2->id;
        $disc23 = $forumgen->create_discussion($record);

        $record->timemodified++;
        $record->userid = $user2->id;
        $record->forum = $forum1->id;
        $record->groupid = $group2->id;
        $disc14 = $forumgen->create_discussion($record);
        $record->forum = $forum2->id;
        $disc24 = $forumgen->create_discussion($record);

        $record->timemodified++;
        $record->userid = $user1->id;
        $record->forum = $forum1->id;
        $record->groupid = $group1->id;
        $disc15 = $forumgen->create_discussion($record);
        $record->forum = $forum2->id;
        $disc25 = $forumgen->create_discussion($record);

        // Admin user can see all groups.
        $this->setAdminUser();
        $neighbours = forum_get_discussion_neighbours($cm1, $disc11, $forum1);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc12->id, $neighbours['next']->id);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc21, $forum2);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc22->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm1, $disc12, $forum1);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEquals($disc13->id, $neighbours['next']->id);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc22, $forum2);
        $this->assertEquals($disc21->id, $neighbours['prev']->id);
        $this->assertEquals($disc23->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm1, $disc13, $forum1);
        $this->assertEquals($disc12->id, $neighbours['prev']->id);
        $this->assertEquals($disc14->id, $neighbours['next']->id);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc23, $forum2);
        $this->assertEquals($disc22->id, $neighbours['prev']->id);
        $this->assertEquals($disc24->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm1, $disc14, $forum1);
        $this->assertEquals($disc13->id, $neighbours['prev']->id);
        $this->assertEquals($disc15->id, $neighbours['next']->id);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc24, $forum2);
        $this->assertEquals($disc23->id, $neighbours['prev']->id);
        $this->assertEquals($disc25->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm1, $disc15, $forum1);
        $this->assertEquals($disc14->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc25, $forum2);
        $this->assertEquals($disc24->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Admin user is only viewing group 1.
        $_POST['group'] = $group1->id;
        $this->assertEquals($group1->id, groups_get_activity_group($cm1, true));
        $this->assertEquals($group1->id, groups_get_activity_group($cm2, true));

        $neighbours = forum_get_discussion_neighbours($cm1, $disc11, $forum1);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc13->id, $neighbours['next']->id);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc21, $forum2);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc23->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm1, $disc13, $forum1);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEquals($disc15->id, $neighbours['next']->id);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc23, $forum2);
        $this->assertEquals($disc21->id, $neighbours['prev']->id);
        $this->assertEquals($disc25->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm1, $disc15, $forum1);
        $this->assertEquals($disc13->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc25, $forum2);
        $this->assertEquals($disc23->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Normal user viewing non-grouped posts (this is only possible in visible groups).
        $this->setUser($user1);
        $_POST['group'] = 0;
        $this->assertEquals(0, groups_get_activity_group($cm1, true));

        // They can see anything in visible groups.
        $neighbours = forum_get_discussion_neighbours($cm1, $disc12, $forum1);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEquals($disc13->id, $neighbours['next']->id);
        $neighbours = forum_get_discussion_neighbours($cm1, $disc13, $forum1);
        $this->assertEquals($disc12->id, $neighbours['prev']->id);
        $this->assertEquals($disc14->id, $neighbours['next']->id);

        // Normal user, orphan of groups, can only see non-grouped posts in separate groups.
        $this->setUser($user2);
        $_POST['group'] = 0;
        $this->assertEquals(0, groups_get_activity_group($cm2, true));

        $neighbours = forum_get_discussion_neighbours($cm2, $disc23, $forum2);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEmpty($neighbours['next']);

        $neighbours = forum_get_discussion_neighbours($cm2, $disc22, $forum2);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc23->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm2, $disc24, $forum2);
        $this->assertEquals($disc23->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Switching to viewing group 1.
        $this->setUser($user1);
        $_POST['group'] = $group1->id;
        $this->assertEquals($group1->id, groups_get_activity_group($cm1, true));
        $this->assertEquals($group1->id, groups_get_activity_group($cm2, true));

        // They can see non-grouped or same group.
        $neighbours = forum_get_discussion_neighbours($cm1, $disc11, $forum1);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc13->id, $neighbours['next']->id);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc21, $forum2);
        $this->assertEmpty($neighbours['prev']);
        $this->assertEquals($disc23->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm1, $disc13, $forum1);
        $this->assertEquals($disc11->id, $neighbours['prev']->id);
        $this->assertEquals($disc15->id, $neighbours['next']->id);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc23, $forum2);
        $this->assertEquals($disc21->id, $neighbours['prev']->id);
        $this->assertEquals($disc25->id, $neighbours['next']->id);

        $neighbours = forum_get_discussion_neighbours($cm1, $disc15, $forum1);
        $this->assertEquals($disc13->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);
        $neighbours = forum_get_discussion_neighbours($cm2, $disc25, $forum2);
        $this->assertEquals($disc23->id, $neighbours['prev']->id);
        $this->assertEmpty($neighbours['next']);

        // Querying the neighbours of a discussion passing the wrong CM.
        $this->expectException('coding_exception');
        forum_get_discussion_neighbours($cm2, $disc11, $forum2);
    }

    public function test_count_discussion_replies_basic(): void {
        list($forum, $discussionids) = $this->create_multiple_discussions_with_replies(10, 5);

        // Count the discussion replies in the forum.
        $result = forum_count_discussion_replies($forum->id);
        $this->assertCount(10, $result);
    }

    public function test_count_discussion_replies_limited(): void {
        list($forum, $discussionids) = $this->create_multiple_discussions_with_replies(10, 5);
        // Adding limits shouldn't make a difference.
        $result = forum_count_discussion_replies($forum->id, "", 20);
        $this->assertCount(10, $result);
    }

    public function test_count_discussion_replies_paginated(): void {
        list($forum, $discussionids) = $this->create_multiple_discussions_with_replies(10, 5);
        // Adding paging shouldn't make any difference.
        $result = forum_count_discussion_replies($forum->id, "", -1, 0, 100);
        $this->assertCount(10, $result);
    }

    public function test_count_discussion_replies_paginated_sorted(): void {
        list($forum, $discussionids) = $this->create_multiple_discussions_with_replies(10, 5);
        // Specifying the forumsort should also give a good result. This follows a different path.
        $result = forum_count_discussion_replies($forum->id, "d.id asc", -1, 0, 100);
        $this->assertCount(10, $result);
        foreach ($result as $row) {
            // Grab the first discussionid.
            $discussionid = array_shift($discussionids);
            $this->assertEquals($discussionid, $row->discussion);
        }
    }

    public function test_count_discussion_replies_limited_sorted(): void {
        list($forum, $discussionids) = $this->create_multiple_discussions_with_replies(10, 5);
        // Adding limits, and a forumsort shouldn't make a difference.
        $result = forum_count_discussion_replies($forum->id, "d.id asc", 20);
        $this->assertCount(10, $result);
        foreach ($result as $row) {
            // Grab the first discussionid.
            $discussionid = array_shift($discussionids);
            $this->assertEquals($discussionid, $row->discussion);
        }
    }

    public function test_count_discussion_replies_paginated_sorted_small(): void {
        list($forum, $discussionids) = $this->create_multiple_discussions_with_replies(10, 5);
        // Grabbing a smaller subset and they should be ordered as expected.
        $result = forum_count_discussion_replies($forum->id, "d.id asc", -1, 0, 5);
        $this->assertCount(5, $result);
        foreach ($result as $row) {
            // Grab the first discussionid.
            $discussionid = array_shift($discussionids);
            $this->assertEquals($discussionid, $row->discussion);
        }
    }

    public function test_count_discussion_replies_paginated_sorted_small_reverse(): void {
        list($forum, $discussionids) = $this->create_multiple_discussions_with_replies(10, 5);
        // Grabbing a smaller subset and they should be ordered as expected.
        $result = forum_count_discussion_replies($forum->id, "d.id desc", -1, 0, 5);
        $this->assertCount(5, $result);
        foreach ($result as $row) {
            // Grab the last discussionid.
            $discussionid = array_pop($discussionids);
            $this->assertEquals($discussionid, $row->discussion);
        }
    }

    public function test_count_discussion_replies_limited_sorted_small_reverse(): void {
        list($forum, $discussionids) = $this->create_multiple_discussions_with_replies(10, 5);
        // Adding limits, and a forumsort shouldn't make a difference.
        $result = forum_count_discussion_replies($forum->id, "d.id desc", 5);
        $this->assertCount(5, $result);
        foreach ($result as $row) {
            // Grab the last discussionid.
            $discussionid = array_pop($discussionids);
            $this->assertEquals($discussionid, $row->discussion);
        }
    }

    /**
     * Test the reply count when used with private replies.
     */
    public function test_forum_count_discussion_replies_private(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', array('course' => $course->id));
        $context = \context_module::instance($forum->cmid);
        $cm = get_coursemodule_from_instance('forum', $forum->id);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id);

        $privilegeduser = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($privilegeduser->id, $course->id, 'editingteacher');

        $otheruser = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($otheruser->id, $course->id);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_forum');

        // Create a discussion with some replies.
        $record = new \stdClass();
        $record->course = $forum->course;
        $record->forum = $forum->id;
        $record->userid = $student->id;
        $discussion = $generator->create_discussion($record);
        $replycount = 5;
        $replyto = $DB->get_record('forum_posts', array('discussion' => $discussion->id));

        // Create a couple of standard replies.
        $post = new \stdClass();
        $post->userid = $student->id;
        $post->discussion = $discussion->id;
        $post->parent = $replyto->id;

        for ($i = 0; $i < $replycount; $i++) {
            $post = $generator->create_post($post);
        }

        // Create a private reply post from the teacher back to the student.
        $reply = new \stdClass();
        $reply->userid = $teacher->id;
        $reply->discussion = $discussion->id;
        $reply->parent = $replyto->id;
        $reply->privatereplyto = $replyto->userid;
        $generator->create_post($reply);

        // The user is the author of the private reply.
        $this->setUser($teacher->id);
        $counts = forum_count_discussion_replies($forum->id);
        $this->assertEquals($replycount + 1, $counts[$discussion->id]->replies);

        // The user is the intended recipient.
        $this->setUser($student->id);
        $counts = forum_count_discussion_replies($forum->id);
        $this->assertEquals($replycount + 1, $counts[$discussion->id]->replies);

        // The user is not the author or recipient, but does have the readprivatereplies capability.
        $this->setUser($privilegeduser->id);
        $counts = forum_count_discussion_replies($forum->id, "", -1, -1, 0, true);
        $this->assertEquals($replycount + 1, $counts[$discussion->id]->replies);

        // The user is not allowed to view this post.
        $this->setUser($otheruser->id);
        $counts = forum_count_discussion_replies($forum->id);
        $this->assertEquals($replycount, $counts[$discussion->id]->replies);
    }

    public function test_discussion_pinned_sort(): void {
        list($forum, $discussionids) = $this->create_multiple_discussions_with_replies(10, 5);
        $cm = get_coursemodule_from_instance('forum', $forum->id);
        $discussions = forum_get_discussions($cm);
        // First discussion should be pinned.
        $first = reset($discussions);
        $this->assertEquals(1, $first->pinned, "First discussion should be pinned discussion");
    }
    public function test_forum_view(): void {
        global $CFG;

        $CFG->enablecompletion = 1;
        $this->resetAfterTest();

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(array('enablecompletion' => 1));
        $forum = $this->getDataGenerator()->create_module('forum', array('course' => $course->id),
                                                            array('completion' => 2, 'completionview' => 1));
        $context = \context_module::instance($forum->cmid);
        $cm = get_coursemodule_from_instance('forum', $forum->id);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();

        $this->setAdminUser();
        forum_view($forum, $course, $cm, $context);

        $events = $sink->get_events();
        // 2 additional events thanks to completion.
        $this->assertCount(3, $events);
        $event = array_pop($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_forum\event\course_module_viewed', $event);
        $this->assertEquals($context, $event->get_context());
        $url = new \moodle_url('/mod/forum/view.php', array('f' => $forum->id));
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);
        $this->assertNotEmpty($event->get_name());

        // Check completion status.
        $completion = new \completion_info($course);
        $completiondata = $completion->get_data($cm);
        $this->assertEquals(1, $completiondata->completionstate);

    }

    /**
     * Test forum_discussion_view.
     */
    public function test_forum_discussion_view(): void {
        global $CFG, $USER;

        $this->resetAfterTest();

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', array('course' => $course->id));
        $discussion = $this->create_single_discussion_with_replies($forum, $USER, 2);

        $context = \context_module::instance($forum->cmid);
        $cm = get_coursemodule_from_instance('forum', $forum->id);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();

        $this->setAdminUser();
        forum_discussion_view($context, $forum, $discussion);

        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = array_pop($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_forum\event\discussion_viewed', $event);
        $this->assertEquals($context, $event->get_context());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());

    }

    /**
     * Create a new course, forum, and user with a number of discussions and replies.
     *
     * @param int $discussioncount The number of discussions to create
     * @param int $replycount The number of replies to create in each discussion
     * @return array Containing the created forum object, and the ids of the created discussions.
     */
    protected function create_multiple_discussions_with_replies($discussioncount, $replycount) {
        $this->resetAfterTest();

        // Setup the content.
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $record = new \stdClass();
        $record->course = $course->id;
        $forum = $this->getDataGenerator()->create_module('forum', $record);

        // Create 10 discussions with replies.
        $discussionids = array();
        for ($i = 0; $i < $discussioncount; $i++) {
            // Pin 3rd discussion.
            if ($i == 3) {
                $discussion = $this->create_single_discussion_pinned_with_replies($forum, $user, $replycount);
            } else {
                $discussion = $this->create_single_discussion_with_replies($forum, $user, $replycount);
            }

            $discussionids[] = $discussion->id;
        }
        return array($forum, $discussionids);
    }

    /**
     * Create a discussion with a number of replies.
     *
     * @param object $forum The forum which has been created
     * @param object $user The user making the discussion and replies
     * @param int $replycount The number of replies
     * @return object $discussion
     */
    protected function create_single_discussion_with_replies($forum, $user, $replycount) {
        global $DB;

        $generator = self::getDataGenerator()->get_plugin_generator('mod_forum');

        $record = new \stdClass();
        $record->course = $forum->course;
        $record->forum = $forum->id;
        $record->userid = $user->id;
        $discussion = $generator->create_discussion($record);

        // Retrieve the first post.
        $replyto = $DB->get_record('forum_posts', array('discussion' => $discussion->id));

        // Create the replies.
        $post = new \stdClass();
        $post->userid = $user->id;
        $post->discussion = $discussion->id;
        $post->parent = $replyto->id;

        for ($i = 0; $i < $replycount; $i++) {
            $generator->create_post($post);
        }

        return $discussion;
    }
    /**
     * Create a discussion with a number of replies.
     *
     * @param object $forum The forum which has been created
     * @param object $user The user making the discussion and replies
     * @param int $replycount The number of replies
     * @return object $discussion
     */
    protected function create_single_discussion_pinned_with_replies($forum, $user, $replycount) {
        global $DB;

        $generator = self::getDataGenerator()->get_plugin_generator('mod_forum');

        $record = new \stdClass();
        $record->course = $forum->course;
        $record->forum = $forum->id;
        $record->userid = $user->id;
        $record->pinned = FORUM_DISCUSSION_PINNED;
        $discussion = $generator->create_discussion($record);

        // Retrieve the first post.
        $replyto = $DB->get_record('forum_posts', array('discussion' => $discussion->id));

        // Create the replies.
        $post = new \stdClass();
        $post->userid = $user->id;
        $post->discussion = $discussion->id;
        $post->parent = $replyto->id;

        for ($i = 0; $i < $replycount; $i++) {
            $generator->create_post($post);
        }

        return $discussion;
    }

    /**
     * Tests for mod_forum_rating_can_see_item_ratings().
     *
     * @throws coding_exception
     * @throws rating_exception
     */
    public function test_mod_forum_rating_can_see_item_ratings(): void {
        global $DB;

        $this->resetAfterTest();

        // Setup test data.
        $course = new \stdClass();
        $course->groupmode = SEPARATEGROUPS;
        $course->groupmodeforce = true;
        $course = $this->getDataGenerator()->create_course($course);
        $forum = $this->getDataGenerator()->create_module('forum', array('course' => $course->id));
        $generator = self::getDataGenerator()->get_plugin_generator('mod_forum');
        $cm = get_coursemodule_from_instance('forum', $forum->id);
        $context = \context_module::instance($cm->id);

        // Create users.
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();
        $user4 = $this->getDataGenerator()->create_user();

        // Groups and stuff.
        $role = $DB->get_record('role', array('shortname' => 'teacher'), '*', MUST_EXIST);
        $this->getDataGenerator()->enrol_user($user1->id, $course->id, $role->id);
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, $role->id);
        $this->getDataGenerator()->enrol_user($user3->id, $course->id, $role->id);
        $this->getDataGenerator()->enrol_user($user4->id, $course->id, $role->id);

        $group1 = $this->getDataGenerator()->create_group(array('courseid' => $course->id));
        $group2 = $this->getDataGenerator()->create_group(array('courseid' => $course->id));
        groups_add_member($group1, $user1);
        groups_add_member($group1, $user2);
        groups_add_member($group2, $user3);
        groups_add_member($group2, $user4);

        $record = new \stdClass();
        $record->course = $forum->course;
        $record->forum = $forum->id;
        $record->userid = $user1->id;
        $record->groupid = $group1->id;
        $discussion = $generator->create_discussion($record);

        // Retrieve the first post.
        $post = $DB->get_record('forum_posts', array('discussion' => $discussion->id));

        $ratingoptions = new \stdClass;
        $ratingoptions->context = $context;
        $ratingoptions->ratingarea = 'post';
        $ratingoptions->component = 'mod_forum';
        $ratingoptions->itemid  = $post->id;
        $ratingoptions->scaleid = 2;
        $ratingoptions->userid  = $user2->id;
        $rating = new \rating($ratingoptions);
        $rating->update_rating(2);

        // Now try to access it as various users.
        unassign_capability('moodle/site:accessallgroups', $role->id);
        $params = array('contextid' => 2,
                        'component' => 'mod_forum',
                        'ratingarea' => 'post',
                        'itemid' => $post->id,
                        'scaleid' => 2);
        $this->setUser($user1);
        $this->assertTrue(mod_forum_rating_can_see_item_ratings($params));
        $this->setUser($user2);
        $this->assertTrue(mod_forum_rating_can_see_item_ratings($params));
        $this->setUser($user3);
        $this->assertFalse(mod_forum_rating_can_see_item_ratings($params));
        $this->setUser($user4);
        $this->assertFalse(mod_forum_rating_can_see_item_ratings($params));

        // Now try with accessallgroups cap and make sure everything is visible.
        assign_capability('moodle/site:accessallgroups', CAP_ALLOW, $role->id, $context->id);
        $this->setUser($user1);
        $this->assertTrue(mod_forum_rating_can_see_item_ratings($params));
        $this->setUser($user2);
        $this->assertTrue(mod_forum_rating_can_see_item_ratings($params));
        $this->setUser($user3);
        $this->assertTrue(mod_forum_rating_can_see_item_ratings($params));
        $this->setUser($user4);
        $this->assertTrue(mod_forum_rating_can_see_item_ratings($params));

        // Change group mode and verify visibility.
        $course->groupmode = VISIBLEGROUPS;
        $DB->update_record('course', $course);
        unassign_capability('moodle/site:accessallgroups', $role->id);
        $this->setUser($user1);
        $this->assertTrue(mod_forum_rating_can_see_item_ratings($params));
        $this->setUser($user2);
        $this->assertTrue(mod_forum_rating_can_see_item_ratings($params));
        $this->setUser($user3);
        $this->assertTrue(mod_forum_rating_can_see_item_ratings($params));
        $this->setUser($user4);
        $this->assertTrue(mod_forum_rating_can_see_item_ratings($params));

    }

    /**
     * Test forum_get_discussions
     */
    public function test_forum_get_discussions_with_groups(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Create course to add the module.
        $course = self::getDataGenerator()->create_course(array('groupmode' => VISIBLEGROUPS, 'groupmodeforce' => 0));
        $user1 = self::getDataGenerator()->create_user();
        $user2 = self::getDataGenerator()->create_user();
        $user3 = self::getDataGenerator()->create_user();

        $role = $DB->get_record('role', array('shortname' => 'student'), '*', MUST_EXIST);
        self::getDataGenerator()->enrol_user($user1->id, $course->id, $role->id);
        self::getDataGenerator()->enrol_user($user2->id, $course->id, $role->id);
        self::getDataGenerator()->enrol_user($user3->id, $course->id, $role->id);

        // Forum forcing separate gropus.
        $record = new \stdClass();
        $record->course = $course->id;
        $forum = self::getDataGenerator()->create_module('forum', $record, array('groupmode' => SEPARATEGROUPS));
        $cm = get_coursemodule_from_instance('forum', $forum->id);

        // Create groups.
        $group1 = self::getDataGenerator()->create_group(array('courseid' => $course->id, 'name' => 'group1'));
        $group2 = self::getDataGenerator()->create_group(array('courseid' => $course->id, 'name' => 'group2'));
        $group3 = self::getDataGenerator()->create_group(array('courseid' => $course->id, 'name' => 'group3'));

        // Add the user1 to g1 and g2 groups.
        groups_add_member($group1->id, $user1->id);
        groups_add_member($group2->id, $user1->id);

        // Add the user 2 and 3 to only one group.
        groups_add_member($group1->id, $user2->id);
        groups_add_member($group3->id, $user3->id);

        // Add a few discussions.
        $record = array();
        $record['course'] = $course->id;
        $record['forum'] = $forum->id;
        $record['userid'] = $user1->id;
        $record['groupid'] = $group1->id;
        $discussiong1u1 = self::getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($record);

        $record['groupid'] = $group2->id;
        $discussiong2u1 = self::getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($record);

        $record['userid'] = $user2->id;
        $record['groupid'] = $group1->id;
        $discussiong1u2 = self::getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($record);

        $record['userid'] = $user3->id;
        $record['groupid'] = $group3->id;
        $discussiong3u3 = self::getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($record);

        self::setUser($user1);

        // Test retrieve discussions not passing the groupid parameter. We will receive only first group discussions.
        $discussions = forum_get_discussions($cm);
        self::assertCount(2, $discussions);
        foreach ($discussions as $discussion) {
            self::assertEquals($group1->id, $discussion->groupid);
        }

        // Get all my discussions.
        $discussions = forum_get_discussions($cm, '', true, -1, -1, false, -1, 0, 0);
        self::assertCount(3, $discussions);

        // Get all my g1 discussions.
        $discussions = forum_get_discussions($cm, '', true, -1, -1, false, -1, 0, $group1->id);
        self::assertCount(2, $discussions);
        foreach ($discussions as $discussion) {
            self::assertEquals($group1->id, $discussion->groupid);
        }

        // Get all my g2 discussions.
        $discussions = forum_get_discussions($cm, '', true, -1, -1, false, -1, 0, $group2->id);
        self::assertCount(1, $discussions);
        $discussion = array_shift($discussions);
        self::assertEquals($group2->id, $discussion->groupid);
        self::assertEquals($user1->id, $discussion->userid);
        self::assertEquals($discussiong2u1->id, $discussion->discussion);

        // Get all my g3 discussions (I'm not enrolled in that group).
        $discussions = forum_get_discussions($cm, '', true, -1, -1, false, -1, 0, $group3->id);
        self::assertCount(0, $discussions);

        // This group does not exist.
        $discussions = forum_get_discussions($cm, '', true, -1, -1, false, -1, 0, $group3->id + 1000);
        self::assertCount(0, $discussions);

        self::setUser($user2);

        // Test retrieve discussions not passing the groupid parameter. We will receive only first group discussions.
        $discussions = forum_get_discussions($cm);
        self::assertCount(2, $discussions);
        foreach ($discussions as $discussion) {
            self::assertEquals($group1->id, $discussion->groupid);
        }

        // Get all my viewable discussions.
        $discussions = forum_get_discussions($cm, '', true, -1, -1, false, -1, 0, 0);
        self::assertCount(2, $discussions);
        foreach ($discussions as $discussion) {
            self::assertEquals($group1->id, $discussion->groupid);
        }

        // Get all my g2 discussions (I'm not enrolled in that group).
        $discussions = forum_get_discussions($cm, '', true, -1, -1, false, -1, 0, $group2->id);
        self::assertCount(0, $discussions);

        // Get all my g3 discussions (I'm not enrolled in that group).
        $discussions = forum_get_discussions($cm, '', true, -1, -1, false, -1, 0, $group3->id);
        self::assertCount(0, $discussions);

    }

    /**
     * Test forum_user_can_post_discussion
     */
    public function test_forum_user_can_post_discussion(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Create course to add the module.
        $course = self::getDataGenerator()->create_course(array('groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1));
        $user = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        // Forum forcing separate gropus.
        $record = new \stdClass();
        $record->course = $course->id;
        $forum = self::getDataGenerator()->create_module('forum', $record, array('groupmode' => SEPARATEGROUPS));
        $cm = get_coursemodule_from_instance('forum', $forum->id);
        $context = \context_module::instance($cm->id);

        self::setUser($user);

        // The user is not enroled in any group, try to post in a forum with separate groups.
        $can = forum_user_can_post_discussion($forum, null, -1, $cm, $context);
        $this->assertFalse($can);

        // Create a group.
        $group = $this->getDataGenerator()->create_group(array('courseid' => $course->id));

        // Try to post in a group the user is not enrolled.
        $can = forum_user_can_post_discussion($forum, $group->id, -1, $cm, $context);
        $this->assertFalse($can);

        // Add the user to a group.
        groups_add_member($group->id, $user->id);

        // Try to post in a group the user is not enrolled.
        $can = forum_user_can_post_discussion($forum, $group->id + 1, -1, $cm, $context);
        $this->assertFalse($can);

        // Now try to post in the user group. (null means it will guess the group).
        $can = forum_user_can_post_discussion($forum, null, -1, $cm, $context);
        $this->assertTrue($can);

        $can = forum_user_can_post_discussion($forum, $group->id, -1, $cm, $context);
        $this->assertTrue($can);

        // Test all groups.
        $can = forum_user_can_post_discussion($forum, -1, -1, $cm, $context);
        $this->assertFalse($can);

        $this->setAdminUser();
        $can = forum_user_can_post_discussion($forum, -1, -1, $cm, $context);
        $this->assertTrue($can);

        // Change forum type.
        $forum->type = 'news';
        $DB->update_record('forum', $forum);

        // Admin can post news.
        $can = forum_user_can_post_discussion($forum, null, -1, $cm, $context);
        $this->assertTrue($can);

        // Normal users don't.
        self::setUser($user);
        $can = forum_user_can_post_discussion($forum, null, -1, $cm, $context);
        $this->assertFalse($can);

        // Change forum type.
        $forum->type = 'eachuser';
        $DB->update_record('forum', $forum);

        // I didn't post yet, so I should be able to post.
        $can = forum_user_can_post_discussion($forum, null, -1, $cm, $context);
        $this->assertTrue($can);

        // Post now.
        $record = new \stdClass();
        $record->course = $course->id;
        $record->userid = $user->id;
        $record->forum = $forum->id;
        $record->groupid = $group->id;
        $discussion = self::getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($record);

        // I already posted, I shouldn't be able to post.
        $can = forum_user_can_post_discussion($forum, null, -1, $cm, $context);
        $this->assertFalse($can);

        // Last check with no groups, normal forum and course.
        $course->groupmode = NOGROUPS;
        $course->groupmodeforce = 0;
        $DB->update_record('course', $course);

        $forum->type = 'general';
        $forum->groupmode = NOGROUPS;
        $DB->update_record('forum', $forum);

        $can = forum_user_can_post_discussion($forum, null, -1, $cm, $context);
        $this->assertTrue($can);
    }

    /**
     * Test forum_user_can_post_discussion_after_cutoff
     */
    public function test_forum_user_can_post_discussion_after_cutoff(): void {
        $this->resetAfterTest(true);

        // Create course to add the module.
        $course = self::getDataGenerator()->create_course(array('groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1));
        $student = self::getDataGenerator()->create_user();
        $teacher = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        // Forum forcing separate gropus.
        $record = new \stdClass();
        $record->course = $course->id;
        $record->cutoffdate = time() - 1;
        $forum = self::getDataGenerator()->create_module('forum', $record);
        $cm = get_coursemodule_from_instance('forum', $forum->id);
        $context = \context_module::instance($cm->id);

        self::setUser($student);

        // Students usually don't have the mod/forum:canoverridecutoff capability.
        $can = forum_user_can_post_discussion($forum, null, -1, $cm, $context);
        $this->assertFalse($can);

        self::setUser($teacher);

        // Teachers usually have the mod/forum:canoverridecutoff capability.
        $can = forum_user_can_post_discussion($forum, null, -1, $cm, $context);
        $this->assertTrue($can);
    }

    /**
     * Test forum_user_has_posted_discussion with no groups.
     */
    public function test_forum_user_has_posted_discussion_no_groups(): void {
        global $CFG;

        $this->resetAfterTest(true);

        $course = self::getDataGenerator()->create_course();
        $author = self::getDataGenerator()->create_user();
        $other = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $course->id);
        $forum = self::getDataGenerator()->create_module('forum', (object) ['course' => $course->id ]);

        self::setUser($author);

        // Neither user has posted.
        $this->assertFalse(forum_user_has_posted_discussion($forum->id, $author->id));
        $this->assertFalse(forum_user_has_posted_discussion($forum->id, $other->id));

        // Post in the forum.
        $record = new \stdClass();
        $record->course = $course->id;
        $record->userid = $author->id;
        $record->forum = $forum->id;
        $discussion = self::getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($record);

        // The author has now posted, but the other user has not.
        $this->assertTrue(forum_user_has_posted_discussion($forum->id, $author->id));
        $this->assertFalse(forum_user_has_posted_discussion($forum->id, $other->id));
    }

    /**
     * Test forum_user_has_posted_discussion with multiple forums
     */
    public function test_forum_user_has_posted_discussion_multiple_forums(): void {
        global $CFG;

        $this->resetAfterTest(true);

        $course = self::getDataGenerator()->create_course();
        $author = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $course->id);
        $forum1 = self::getDataGenerator()->create_module('forum', (object) ['course' => $course->id ]);
        $forum2 = self::getDataGenerator()->create_module('forum', (object) ['course' => $course->id ]);

        self::setUser($author);

        // No post in either forum.
        $this->assertFalse(forum_user_has_posted_discussion($forum1->id, $author->id));
        $this->assertFalse(forum_user_has_posted_discussion($forum2->id, $author->id));

        // Post in the forum.
        $record = new \stdClass();
        $record->course = $course->id;
        $record->userid = $author->id;
        $record->forum = $forum1->id;
        $discussion = self::getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($record);

        // The author has now posted in forum1, but not forum2.
        $this->assertTrue(forum_user_has_posted_discussion($forum1->id, $author->id));
        $this->assertFalse(forum_user_has_posted_discussion($forum2->id, $author->id));
    }

    /**
     * Test forum_user_has_posted_discussion with multiple groups.
     */
    public function test_forum_user_has_posted_discussion_multiple_groups(): void {
        global $CFG;

        $this->resetAfterTest(true);

        $course = self::getDataGenerator()->create_course();
        $author = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $course->id);

        $group1 = $this->getDataGenerator()->create_group(array('courseid' => $course->id));
        $group2 = $this->getDataGenerator()->create_group(array('courseid' => $course->id));
        groups_add_member($group1->id, $author->id);
        groups_add_member($group2->id, $author->id);

        $forum = self::getDataGenerator()->create_module('forum', (object) ['course' => $course->id ], [
                    'groupmode' => SEPARATEGROUPS,
                ]);

        self::setUser($author);

        // The user has not posted in either group.
        $this->assertFalse(forum_user_has_posted_discussion($forum->id, $author->id));
        $this->assertFalse(forum_user_has_posted_discussion($forum->id, $author->id, $group1->id));
        $this->assertFalse(forum_user_has_posted_discussion($forum->id, $author->id, $group2->id));

        // Post in one group.
        $record = new \stdClass();
        $record->course = $course->id;
        $record->userid = $author->id;
        $record->forum = $forum->id;
        $record->groupid = $group1->id;
        $discussion = self::getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($record);

        // The author has now posted in one group, but the other user has not.
        $this->assertTrue(forum_user_has_posted_discussion($forum->id, $author->id));
        $this->assertTrue(forum_user_has_posted_discussion($forum->id, $author->id, $group1->id));
        $this->assertFalse(forum_user_has_posted_discussion($forum->id, $author->id, $group2->id));

        // Post in the other group.
        $record = new \stdClass();
        $record->course = $course->id;
        $record->userid = $author->id;
        $record->forum = $forum->id;
        $record->groupid = $group2->id;
        $discussion = self::getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($record);

        // The author has now posted in one group, but the other user has not.
        $this->assertTrue(forum_user_has_posted_discussion($forum->id, $author->id));
        $this->assertTrue(forum_user_has_posted_discussion($forum->id, $author->id, $group1->id));
        $this->assertTrue(forum_user_has_posted_discussion($forum->id, $author->id, $group2->id));
    }

    /**
     * Test the logic for forum_get_user_posted_mailnow where the user can select if qanda forum post should be sent without delay
     *
     * @covers ::forum_get_user_posted_mailnow
     */
    public function test_forum_get_user_posted_mailnow(): void {
        $this->resetAfterTest();

        // Create a forum.
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $author = $this->getDataGenerator()->create_user();
        $authorid = $author->id;
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_forum');

        // Create a discussion.
        $record = new \stdClass();
        $record->course = $forum->course;
        $record->forum = $forum->id;
        $record->userid = $authorid;
        $discussion = $generator->create_discussion($record);
        $did = $discussion->id;

        // Return False if no post exists with 'mailnow' selected.
        $generator->create_post(['userid' => $authorid, 'discussion' => $did, 'forum' => $forum->id, 'mailnow' => 0]);
        $result = forum_get_user_posted_mailnow($did, $authorid);
        $this->assertFalse($result);

        // Return True only if any post has 'mailnow' selected.
        $generator->create_post(['userid' => $authorid, 'discussion' => $did, 'forum' => $forum->id, 'mailnow' => 1]);
        $result = forum_get_user_posted_mailnow($did, $authorid);
        $this->assertTrue($result);
    }

    /**
     * Tests the mod_forum_myprofile_navigation() function.
     */
    public function test_mod_forum_myprofile_navigation(): void {
        $this->resetAfterTest(true);

        // Set up the test.
        $tree = new \core_user\output\myprofile\tree();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $iscurrentuser = true;

        // Set as the current user.
        $this->setUser($user);

        // Check the node tree is correct.
        mod_forum_myprofile_navigation($tree, $user, $iscurrentuser, $course);
        $reflector = new \ReflectionObject($tree);
        $nodes = $reflector->getProperty('nodes');
        $this->assertArrayHasKey('forumposts', $nodes->getValue($tree));
        $this->assertArrayHasKey('forumdiscussions', $nodes->getValue($tree));
    }

    /**
     * Tests the mod_forum_myprofile_navigation() function as a guest.
     */
    public function test_mod_forum_myprofile_navigation_as_guest(): void {
        global $USER;

        $this->resetAfterTest(true);

        // Set up the test.
        $tree = new \core_user\output\myprofile\tree();
        $course = $this->getDataGenerator()->create_course();
        $iscurrentuser = true;

        // Set user as guest.
        $this->setGuestUser();

        // Check the node tree is correct.
        mod_forum_myprofile_navigation($tree, $USER, $iscurrentuser, $course);
        $reflector = new \ReflectionObject($tree);
        $nodes = $reflector->getProperty('nodes');
        $this->assertArrayNotHasKey('forumposts', $nodes->getValue($tree));
        $this->assertArrayNotHasKey('forumdiscussions', $nodes->getValue($tree));
    }

    /**
     * Tests the mod_forum_myprofile_navigation() function as a user viewing another user's profile.
     */
    public function test_mod_forum_myprofile_navigation_different_user(): void {
        $this->resetAfterTest(true);

        // Set up the test.
        $tree = new \core_user\output\myprofile\tree();
        $user = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $iscurrentuser = true;

        // Set to different user's profile.
        $this->setUser($user2);

        // Check the node tree is correct.
        mod_forum_myprofile_navigation($tree, $user, $iscurrentuser, $course);
        $reflector = new \ReflectionObject($tree);
        $nodes = $reflector->getProperty('nodes');
        $this->assertArrayHasKey('forumposts', $nodes->getValue($tree));
        $this->assertArrayHasKey('forumdiscussions', $nodes->getValue($tree));
    }

    /**
     * Test test_pinned_discussion_with_group.
     */
    public function test_pinned_discussion_with_group(): void {
        global $SESSION;

        $this->resetAfterTest();
        $course1 = $this->getDataGenerator()->create_course();
        $group1 = $this->getDataGenerator()->create_group(array('courseid' => $course1->id));

        // Create an author user.
        $author = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $course1->id);

        // Create two viewer users - one in a group, one not.
        $viewer1 = $this->getDataGenerator()->create_user((object) array('trackforums' => 1));
        $this->getDataGenerator()->enrol_user($viewer1->id, $course1->id);

        $viewer2 = $this->getDataGenerator()->create_user((object) array('trackforums' => 1));
        $this->getDataGenerator()->enrol_user($viewer2->id, $course1->id);
        $this->getDataGenerator()->create_group_member(array('userid' => $viewer2->id, 'groupid' => $group1->id));

        $forum1 = $this->getDataGenerator()->create_module('forum', (object) array(
            'course' => $course1->id,
            'groupmode' => SEPARATEGROUPS,
        ));

        $coursemodule = get_coursemodule_from_instance('forum', $forum1->id);

        $alldiscussions = array();
        $group1discussions = array();

        // Create 4 discussions in all participants group and group1, where the first
        // discussion is pinned in each group.
        $allrecord = new \stdClass();
        $allrecord->course = $course1->id;
        $allrecord->userid = $author->id;
        $allrecord->forum = $forum1->id;
        $allrecord->pinned = FORUM_DISCUSSION_PINNED;

        $group1record = new \stdClass();
        $group1record->course = $course1->id;
        $group1record->userid = $author->id;
        $group1record->forum = $forum1->id;
        $group1record->groupid = $group1->id;
        $group1record->pinned = FORUM_DISCUSSION_PINNED;

        $alldiscussions[] = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($allrecord);
        $group1discussions[] = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($group1record);

        // Create unpinned discussions.
        $allrecord->pinned = FORUM_DISCUSSION_UNPINNED;
        $group1record->pinned = FORUM_DISCUSSION_UNPINNED;
        for ($i = 0; $i < 3; $i++) {
            $alldiscussions[] = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($allrecord);
            $group1discussions[] = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($group1record);
        }

        // As viewer1 (no group). This user shouldn't see any of group1's discussions
        // so their expected discussion order is (where rightmost is highest priority):
        // Ad1, ad2, ad3, ad0.
        $this->setUser($viewer1->id);

        // CHECK 1.
        // Take the neighbours of ad3, which should be prev: ad2 and next: ad0.
        $neighbours = forum_get_discussion_neighbours($coursemodule, $alldiscussions[3], $forum1);
        // Ad2 check.
        $this->assertEquals($alldiscussions[2]->id, $neighbours['prev']->id);
        // Ad0 check.
        $this->assertEquals($alldiscussions[0]->id, $neighbours['next']->id);

        // CHECK 2.
        // Take the neighbours of ad0, which should be prev: ad3 and next: null.
        $neighbours = forum_get_discussion_neighbours($coursemodule, $alldiscussions[0], $forum1);
        // Ad3 check.
        $this->assertEquals($alldiscussions[3]->id, $neighbours['prev']->id);
        // Null check.
        $this->assertEmpty($neighbours['next']);

        // CHECK 3.
        // Take the neighbours of ad1, which should be prev: null and next: ad2.
        $neighbours = forum_get_discussion_neighbours($coursemodule, $alldiscussions[1], $forum1);
        // Null check.
        $this->assertEmpty($neighbours['prev']);
        // Ad2 check.
        $this->assertEquals($alldiscussions[2]->id, $neighbours['next']->id);

        // Temporary hack to workaround for MDL-52656.
        $SESSION->currentgroup = null;

        // As viewer2 (group1). This user should see all of group1's posts and the all participants group.
        // The expected discussion order is (rightmost is highest priority):
        // Ad1, gd1, ad2, gd2, ad3, gd3, ad0, gd0.
        $this->setUser($viewer2->id);

        // CHECK 1.
        // Take the neighbours of ad1, which should be prev: null and next: gd1.
        $neighbours = forum_get_discussion_neighbours($coursemodule, $alldiscussions[1], $forum1);
        // Null check.
        $this->assertEmpty($neighbours['prev']);
        // Gd1 check.
        $this->assertEquals($group1discussions[1]->id, $neighbours['next']->id);

        // CHECK 2.
        // Take the neighbours of ad3, which should be prev: gd2 and next: gd3.
        $neighbours = forum_get_discussion_neighbours($coursemodule, $alldiscussions[3], $forum1);
        // Gd2 check.
        $this->assertEquals($group1discussions[2]->id, $neighbours['prev']->id);
        // Gd3 check.
        $this->assertEquals($group1discussions[3]->id, $neighbours['next']->id);

        // CHECK 3.
        // Take the neighbours of gd3, which should be prev: ad3 and next: ad0.
        $neighbours = forum_get_discussion_neighbours($coursemodule, $group1discussions[3], $forum1);
        // Ad3 check.
        $this->assertEquals($alldiscussions[3]->id, $neighbours['prev']->id);
        // Ad0 check.
        $this->assertEquals($alldiscussions[0]->id, $neighbours['next']->id);

        // CHECK 4.
        // Take the neighbours of gd0, which should be prev: ad0 and next: null.
        $neighbours = forum_get_discussion_neighbours($coursemodule, $group1discussions[0], $forum1);
        // Ad0 check.
        $this->assertEquals($alldiscussions[0]->id, $neighbours['prev']->id);
        // Null check.
        $this->assertEmpty($neighbours['next']);
    }

    /**
     * Test test_pinned_with_timed_discussions.
     */
    public function test_pinned_with_timed_discussions(): void {
        global $CFG;

        $CFG->forum_enabletimedposts = true;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        // Create an user.
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        // Create a forum.
        $record = new \stdClass();
        $record->course = $course->id;
        $forum = $this->getDataGenerator()->create_module('forum', (object) array(
            'course' => $course->id,
            'groupmode' => SEPARATEGROUPS,
        ));

        $coursemodule = get_coursemodule_from_instance('forum', $forum->id);
        $now = time();
        $discussions = array();
        $discussiongenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');

        $record = new \stdClass();
        $record->course = $course->id;
        $record->userid = $user->id;
        $record->forum = $forum->id;
        $record->pinned = FORUM_DISCUSSION_PINNED;
        $record->timemodified = $now;

        $discussions[] = $discussiongenerator->create_discussion($record);

        $record->pinned = FORUM_DISCUSSION_UNPINNED;
        $record->timestart = $now + 10;

        $discussions[] = $discussiongenerator->create_discussion($record);

        $record->timestart = $now;

        $discussions[] = $discussiongenerator->create_discussion($record);

        // Expected order of discussions:
        // D2, d1, d0.
        $this->setUser($user->id);

        // CHECK 1.
        $neighbours = forum_get_discussion_neighbours($coursemodule, $discussions[2], $forum);
        // Null check.
        $this->assertEmpty($neighbours['prev']);
        // D1 check.
        $this->assertEquals($discussions[1]->id, $neighbours['next']->id);

        // CHECK 2.
        $neighbours = forum_get_discussion_neighbours($coursemodule, $discussions[1], $forum);
        // D2 check.
        $this->assertEquals($discussions[2]->id, $neighbours['prev']->id);
        // D0 check.
        $this->assertEquals($discussions[0]->id, $neighbours['next']->id);

        // CHECK 3.
        $neighbours = forum_get_discussion_neighbours($coursemodule, $discussions[0], $forum);
        // D2 check.
        $this->assertEquals($discussions[1]->id, $neighbours['prev']->id);
        // Null check.
        $this->assertEmpty($neighbours['next']);
    }

    /**
     * Test test_pinned_timed_discussions_with_timed_discussions.
     */
    public function test_pinned_timed_discussions_with_timed_discussions(): void {
        global $CFG;

        $CFG->forum_enabletimedposts = true;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        // Create an user.
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        // Create a forum.
        $record = new \stdClass();
        $record->course = $course->id;
        $forum = $this->getDataGenerator()->create_module('forum', (object) array(
            'course' => $course->id,
            'groupmode' => SEPARATEGROUPS,
        ));

        $coursemodule = get_coursemodule_from_instance('forum', $forum->id);
        $now = time();
        $discussions = array();
        $discussiongenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');

        $record = new \stdClass();
        $record->course = $course->id;
        $record->userid = $user->id;
        $record->forum = $forum->id;
        $record->pinned = FORUM_DISCUSSION_PINNED;
        $record->timemodified = $now;
        $record->timestart = $now + 10;

        $discussions[] = $discussiongenerator->create_discussion($record);

        $record->pinned = FORUM_DISCUSSION_UNPINNED;

        $discussions[] = $discussiongenerator->create_discussion($record);

        $record->timestart = $now;

        $discussions[] = $discussiongenerator->create_discussion($record);

        $record->pinned = FORUM_DISCUSSION_PINNED;

        $discussions[] = $discussiongenerator->create_discussion($record);

        // Expected order of discussions:
        // D2, d1, d3, d0.
        $this->setUser($user->id);

        // CHECK 1.
        $neighbours = forum_get_discussion_neighbours($coursemodule, $discussions[2], $forum);
        // Null check.
        $this->assertEmpty($neighbours['prev']);
        // D1 check.
        $this->assertEquals($discussions[1]->id, $neighbours['next']->id);

        // CHECK 2.
        $neighbours = forum_get_discussion_neighbours($coursemodule, $discussions[1], $forum);
        // D2 check.
        $this->assertEquals($discussions[2]->id, $neighbours['prev']->id);
        // D3 check.
        $this->assertEquals($discussions[3]->id, $neighbours['next']->id);

        // CHECK 3.
        $neighbours = forum_get_discussion_neighbours($coursemodule, $discussions[3], $forum);
        // D1 check.
        $this->assertEquals($discussions[1]->id, $neighbours['prev']->id);
        // D0 check.
        $this->assertEquals($discussions[0]->id, $neighbours['next']->id);

        // CHECK 4.
        $neighbours = forum_get_discussion_neighbours($coursemodule, $discussions[0], $forum);
        // D3 check.
        $this->assertEquals($discussions[3]->id, $neighbours['prev']->id);
        // Null check.
        $this->assertEmpty($neighbours['next']);
    }

    /**
     * Test for forum_is_author_hidden.
     */
    public function test_forum_is_author_hidden(): void {
        // First post, different forum type.
        $post = (object) ['parent' => 0];
        $forum = (object) ['type' => 'standard'];
        $this->assertFalse(forum_is_author_hidden($post, $forum));

        // Child post, different forum type.
        $post->parent = 1;
        $this->assertFalse(forum_is_author_hidden($post, $forum));

        // First post, single simple discussion forum type.
        $post->parent = 0;
        $forum->type = 'single';
        $this->assertTrue(forum_is_author_hidden($post, $forum));

        // Child post, single simple discussion forum type.
        $post->parent = 1;
        $this->assertFalse(forum_is_author_hidden($post, $forum));

        // Incorrect parameters: $post.
        $this->expectException('coding_exception');
        $this->expectExceptionMessage('$post->parent must be set.');
        unset($post->parent);
        forum_is_author_hidden($post, $forum);

        // Incorrect parameters: $forum.
        $this->expectException('coding_exception');
        $this->expectExceptionMessage('$forum->type must be set.');
        unset($forum->type);
        forum_is_author_hidden($post, $forum);
    }

    /**
     * Test the forum_discussion_is_locked function.
     *
     * @dataProvider forum_discussion_is_locked_provider
     * @param   \stdClass $forum
     * @param   \stdClass $discussion
     * @param   bool        $expect
     */
    public function test_forum_discussion_is_locked($forum, $discussion, $expect): void {
        $this->resetAfterTest();

        $datagenerator = $this->getDataGenerator();
        $plugingenerator = $datagenerator->get_plugin_generator('mod_forum');

        $course = $datagenerator->create_course();
        $user = $datagenerator->create_user();
        $forum = $datagenerator->create_module('forum', (object) array_merge([
            'course' => $course->id
        ], $forum));
        $discussion = $plugingenerator->create_discussion((object) array_merge([
            'course' => $course->id,
            'userid' => $user->id,
            'forum' => $forum->id,
        ], $discussion));

        $this->assertEquals($expect, forum_discussion_is_locked($forum, $discussion));
    }

    /**
     * Dataprovider for forum_discussion_is_locked tests.
     *
     * @return  array
     */
    public static function forum_discussion_is_locked_provider(): array {
        return [
            'Unlocked: lockdiscussionafter is false' => [
                ['lockdiscussionafter' => false],
                [],
                false
            ],
            'Unlocked: lockdiscussionafter is set; forum is of type single; post is recent' => [
                ['lockdiscussionafter' => DAYSECS, 'type' => 'single'],
                ['timemodified' => time()],
                false
            ],
            'Unlocked: lockdiscussionafter is set; forum is of type single; post is old' => [
                ['lockdiscussionafter' => MINSECS, 'type' => 'single'],
                ['timemodified' => time() - DAYSECS],
                false
            ],
            'Unlocked: lockdiscussionafter is set; forum is of type eachuser; post is recent' => [
                ['lockdiscussionafter' => DAYSECS, 'type' => 'eachuser'],
                ['timemodified' => time()],
                false
            ],
            'Locked: lockdiscussionafter is set; forum is of type eachuser; post is old' => [
                ['lockdiscussionafter' => MINSECS, 'type' => 'eachuser'],
                ['timemodified' => time() - DAYSECS],
                true
            ],
        ];
    }

    /**
     * Test the forum_is_cutoff_date_reached function.
     *
     * @dataProvider forum_is_cutoff_date_reached_provider
     * @param   array   $forum
     * @param   bool    $expect
     */
    public function test_forum_is_cutoff_date_reached($forum, $expect): void {
        $this->resetAfterTest();

        $datagenerator = $this->getDataGenerator();
        $course = $datagenerator->create_course();
        $forum = $datagenerator->create_module('forum', (object) array_merge([
            'course' => $course->id
        ], $forum));

        $this->assertEquals($expect, forum_is_cutoff_date_reached($forum));
    }

    /**
     * Dataprovider for forum_is_cutoff_date_reached tests.
     *
     * @return  array
     */
    public static function forum_is_cutoff_date_reached_provider(): array {
        $now = time();
        return [
            'cutoffdate is unset' => [
                [],
                false
            ],
            'cutoffdate is 0' => [
                ['cutoffdate' => 0],
                false
            ],
            'cutoffdate is set and is in future' => [
                ['cutoffdate' => $now + 86400],
                false
            ],
            'cutoffdate is set and is in past' => [
                ['cutoffdate' => $now - 86400],
                true
            ],
        ];
    }

    /**
     * Test the forum_is_due_date_reached function.
     *
     * @dataProvider forum_is_due_date_reached_provider
     * @param   \stdClass $forum
     * @param   bool        $expect
     */
    public function test_forum_is_due_date_reached($forum, $expect): void {
        $this->resetAfterTest();

        $this->setAdminUser();

        $datagenerator = $this->getDataGenerator();
        $course = $datagenerator->create_course();
        $forum = $datagenerator->create_module('forum', (object) array_merge([
            'course' => $course->id
        ], $forum));

        $this->assertEquals($expect, forum_is_due_date_reached($forum));
    }

    /**
     * Dataprovider for forum_is_due_date_reached tests.
     *
     * @return  array
     */
    public static function forum_is_due_date_reached_provider(): array {
        $now = time();
        return [
            'duedate is unset' => [
                [],
                false
            ],
            'duedate is 0' => [
                ['duedate' => 0],
                false
            ],
            'duedate is set and is in future' => [
                ['duedate' => $now + 86400],
                false
            ],
            'duedate is set and is in past' => [
                ['duedate' => $now - 86400],
                true
            ],
        ];
    }

    /**
     * Test that {@link forum_update_post()} keeps correct forum_discussions usermodified.
     */
    public function test_forum_update_post_keeps_discussions_usermodified(): void {
        global $DB;

        $this->resetAfterTest();

        // Let there be light.
        $teacher = self::getDataGenerator()->create_user();
        $student = self::getDataGenerator()->create_user();
        $course = self::getDataGenerator()->create_course();

        $forum = self::getDataGenerator()->create_module('forum', (object)[
            'course' => $course->id,
        ]);

        $generator = self::getDataGenerator()->get_plugin_generator('mod_forum');

        // Let the teacher start a discussion.
        $discussion = $generator->create_discussion((object)[
            'course' => $course->id,
            'userid' => $teacher->id,
            'forum' => $forum->id,
        ]);

        // On this freshly created discussion, the teacher is the author of the last post.
        $this->assertEquals($teacher->id, $DB->get_field('forum_discussions', 'usermodified', ['id' => $discussion->id]));

        // Fetch modified timestamp of the discussion.
        $discussionmodified = $DB->get_field('forum_discussions', 'timemodified', ['id' => $discussion->id]);
        $pasttime = $discussionmodified - 3600;

        // Adjust the discussion modified timestamp back an hour, so it's in the past.
        $adjustment = (object)[
            'id' => $discussion->id,
            'timemodified' => $pasttime,
        ];
        $DB->update_record('forum_discussions', $adjustment);

        // Let the student reply to the teacher's post.
        $reply = $generator->create_post((object)[
            'course' => $course->id,
            'userid' => $student->id,
            'forum' => $forum->id,
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
        ]);

        // The student should now be the last post's author.
        $this->assertEquals($student->id, $DB->get_field('forum_discussions', 'usermodified', ['id' => $discussion->id]));

        // Fetch modified timestamp of the discussion and student's post.
        $discussionmodified = $DB->get_field('forum_discussions', 'timemodified', ['id' => $discussion->id]);
        $postmodified = $DB->get_field('forum_posts', 'modified', ['id' => $reply->id]);

        // Discussion modified time should be updated to be equal to the newly created post's time.
        $this->assertEquals($discussionmodified, $postmodified);

        // Adjust the discussion and post timestamps, so they are in the past.
        $adjustment = (object)[
            'id' => $discussion->id,
            'timemodified' => $pasttime,
        ];
        $DB->update_record('forum_discussions', $adjustment);

        $adjustment = (object)[
            'id' => $reply->id,
            'modified' => $pasttime,
        ];
        $DB->update_record('forum_posts', $adjustment);

        // The discussion and student's post time should now be an hour in the past.
        $this->assertEquals($pasttime, $DB->get_field('forum_discussions', 'timemodified', ['id' => $discussion->id]));
        $this->assertEquals($pasttime, $DB->get_field('forum_posts', 'modified', ['id' => $reply->id]));

        // Let the teacher edit the student's reply.
        $this->setUser($teacher->id);
        $newpost = (object)[
            'id' => $reply->id,
            'itemid' => 0,
            'subject' => 'Amended subject',
        ];
        forum_update_post($newpost, null);

        // The student should still be the last post's author.
        $this->assertEquals($student->id, $DB->get_field('forum_discussions', 'usermodified', ['id' => $discussion->id]));

        // The discussion modified time should not have changed.
        $this->assertEquals($pasttime, $DB->get_field('forum_discussions', 'timemodified', ['id' => $discussion->id]));

        // The post time should be updated.
        $this->assertGreaterThan($pasttime, $DB->get_field('forum_posts', 'modified', ['id' => $reply->id]));
    }

    public function test_forum_core_calendar_provide_event_action(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Create the activity.
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', array('course' => $course->id,
            'completionreplies' => 5, 'completiondiscussions' => 2));

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $forum->id,
            \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED);

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Decorate action event.
        $actionevent = mod_forum_core_calendar_provide_event_action($event, $factory);

        // Confirm the event was decorated.
        $this->assertInstanceOf('\core_calendar\local\event\value_objects\action', $actionevent);
        $this->assertEquals(get_string('view'), $actionevent->get_name());
        $this->assertInstanceOf('moodle_url', $actionevent->get_url());
        $this->assertEquals(7, $actionevent->get_item_count());
        $this->assertTrue($actionevent->is_actionable());
    }

    public function test_forum_core_calendar_provide_event_action_in_hidden_section(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course();

        // Create a student.
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // Create the activity.
        $forum = $this->getDataGenerator()->create_module('forum', array('course' => $course->id,
                'completionreplies' => 5, 'completiondiscussions' => 2));

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $forum->id,
                \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED);

        // Set sections 0 as hidden.
        set_section_visible($course->id, 0, 0);

        // Now, log out.
        $CFG->forcelogin = true; // We don't want to be logged in as guest, as guest users might still have some capabilities.
        $this->setUser();

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Decorate action event for the student.
        $actionevent = mod_forum_core_calendar_provide_event_action($event, $factory, $student->id);

        // Confirm the event is not shown at all.
        $this->assertNull($actionevent);
    }

    public function test_forum_core_calendar_provide_event_action_for_user(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course();

        // Create a student.
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // Create the activity.
        $forum = $this->getDataGenerator()->create_module('forum', array('course' => $course->id,
                'completionreplies' => 5, 'completiondiscussions' => 2));

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $forum->id,
                \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED);

        // Now log out.
        $CFG->forcelogin = true; // We don't want to be logged in as guest, as guest users might still have some capabilities.
        $this->setUser();

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Decorate action event for the student.
        $actionevent = mod_forum_core_calendar_provide_event_action($event, $factory, $student->id);

        // Confirm the event was decorated.
        $this->assertInstanceOf('\core_calendar\local\event\value_objects\action', $actionevent);
        $this->assertEquals(get_string('view'), $actionevent->get_name());
        $this->assertInstanceOf('moodle_url', $actionevent->get_url());
        $this->assertEquals(7, $actionevent->get_item_count());
        $this->assertTrue($actionevent->is_actionable());
    }

    public function test_forum_core_calendar_provide_event_action_as_non_user(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Create the activity.
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', array('course' => $course->id));

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $forum->id,
            \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED);

        // Log out the user and set force login to true.
        \core\session\manager::init_empty_session();
        $CFG->forcelogin = true;

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Decorate action event.
        $actionevent = mod_forum_core_calendar_provide_event_action($event, $factory);

        // Ensure result was null.
        $this->assertNull($actionevent);
    }

    public function test_forum_core_calendar_provide_event_action_already_completed(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        $CFG->enablecompletion = 1;

        // Create the activity.
        $course = $this->getDataGenerator()->create_course(array('enablecompletion' => 1));
        $forum = $this->getDataGenerator()->create_module('forum', array('course' => $course->id),
            array('completion' => 2, 'completionview' => 1, 'completionexpected' => time() + DAYSECS));

        // Get some additional data.
        $cm = get_coursemodule_from_instance('forum', $forum->id);

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $forum->id,
            \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED);

        // Mark the activity as completed.
        $completion = new \completion_info($course);
        $completion->set_module_viewed($cm);

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Decorate action event.
        $actionevent = mod_forum_core_calendar_provide_event_action($event, $factory);

        // Ensure result was null.
        $this->assertNull($actionevent);
    }

    public function test_forum_core_calendar_provide_event_action_already_completed_for_user(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        $CFG->enablecompletion = 1;

        // Create a course.
        $course = $this->getDataGenerator()->create_course(array('enablecompletion' => 1));

        // Create a student.
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // Create the activity.
        $forum = $this->getDataGenerator()->create_module('forum', array('course' => $course->id),
            array('completion' => 2, 'completionview' => 1, 'completionexpected' => time() + DAYSECS));

        // Get some additional data.
        $cm = get_coursemodule_from_instance('forum', $forum->id);

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $forum->id,
            \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED);

        // Mark the activity as completed for the student.
        $completion = new \completion_info($course);
        $completion->set_module_viewed($cm, $student->id);

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Decorate action event.
        $actionevent = mod_forum_core_calendar_provide_event_action($event, $factory, $student->id);

        // Ensure result was null.
        $this->assertNull($actionevent);
    }

    public function test_mod_forum_get_tagged_posts(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Setup test data.
        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $course3 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $course1 = $this->getDataGenerator()->create_course();
        $forum1 = $this->getDataGenerator()->create_module('forum', array('course' => $course1->id));
        $forum2 = $this->getDataGenerator()->create_module('forum', array('course' => $course2->id));
        $forum3 = $this->getDataGenerator()->create_module('forum', array('course' => $course3->id));
        $post11 = $forumgenerator->create_content($forum1, array('tags' => array('Cats', 'Dogs')));
        $post12 = $forumgenerator->create_content($forum1, array('tags' => array('Cats', 'mice')));
        $post13 = $forumgenerator->create_content($forum1, array('tags' => array('Cats')));
        $post14 = $forumgenerator->create_content($forum1);
        $post15 = $forumgenerator->create_content($forum1, array('tags' => array('Cats')));
        $post16 = $forumgenerator->create_content($forum1, array('tags' => array('Cats'), 'hidden' => true));
        $post21 = $forumgenerator->create_content($forum2, array('tags' => array('Cats')));
        $post22 = $forumgenerator->create_content($forum2, array('tags' => array('Cats', 'Dogs')));
        $post23 = $forumgenerator->create_content($forum2, array('tags' => array('mice', 'Cats')));
        $post31 = $forumgenerator->create_content($forum3, array('tags' => array('mice', 'Cats')));

        $tag = \core_tag_tag::get_by_name(0, 'Cats');

        // Admin can see everything.
        $res = mod_forum_get_tagged_posts($tag, /*$exclusivemode = */false,
            /*$fromctx = */0, /*$ctx = */0, /*$rec = */1, /*$post = */0);
        $this->assertMatchesRegularExpression('/'.$post11->subject.'</', $res->content);
        $this->assertMatchesRegularExpression('/'.$post12->subject.'</', $res->content);
        $this->assertMatchesRegularExpression('/'.$post13->subject.'</', $res->content);
        $this->assertDoesNotMatchRegularExpression('/'.$post14->subject.'</', $res->content);
        $this->assertMatchesRegularExpression('/'.$post15->subject.'</', $res->content);
        $this->assertMatchesRegularExpression('/'.$post16->subject.'</', $res->content);
        $this->assertDoesNotMatchRegularExpression('/'.$post21->subject.'</', $res->content);
        $this->assertDoesNotMatchRegularExpression('/'.$post22->subject.'</', $res->content);
        $this->assertDoesNotMatchRegularExpression('/'.$post23->subject.'</', $res->content);
        $this->assertDoesNotMatchRegularExpression('/'.$post31->subject.'</', $res->content);
        $this->assertEmpty($res->prevpageurl);
        $this->assertNotEmpty($res->nextpageurl);
        $res = mod_forum_get_tagged_posts($tag, /*$exclusivemode = */false,
            /*$fromctx = */0, /*$ctx = */0, /*$rec = */1, /*$post = */1);
        $this->assertDoesNotMatchRegularExpression('/'.$post11->subject.'</', $res->content);
        $this->assertDoesNotMatchRegularExpression('/'.$post12->subject.'</', $res->content);
        $this->assertDoesNotMatchRegularExpression('/'.$post13->subject.'</', $res->content);
        $this->assertDoesNotMatchRegularExpression('/'.$post14->subject.'</', $res->content);
        $this->assertDoesNotMatchRegularExpression('/'.$post15->subject.'</', $res->content);
        $this->assertDoesNotMatchRegularExpression('/'.$post16->subject.'</', $res->content);
        $this->assertMatchesRegularExpression('/'.$post21->subject.'</', $res->content);
        $this->assertMatchesRegularExpression('/'.$post22->subject.'</', $res->content);
        $this->assertMatchesRegularExpression('/'.$post23->subject.'</', $res->content);
        $this->assertMatchesRegularExpression('/'.$post31->subject.'</', $res->content);
        $this->assertNotEmpty($res->prevpageurl);
        $this->assertEmpty($res->nextpageurl);

        // Create and enrol a user.
        $student = self::getDataGenerator()->create_user();
        $studentrole = $DB->get_record('role', array('shortname' => 'student'));
        $this->getDataGenerator()->enrol_user($student->id, $course1->id, $studentrole->id, 'manual');
        $this->getDataGenerator()->enrol_user($student->id, $course2->id, $studentrole->id, 'manual');
        $this->setUser($student);
        \core_tag_index_builder::reset_caches();

        // User can not see posts in course 3 because he is not enrolled.
        $res = mod_forum_get_tagged_posts($tag, /*$exclusivemode = */false,
            /*$fromctx = */0, /*$ctx = */0, /*$rec = */1, /*$post = */1);
        $this->assertMatchesRegularExpression('/'.$post22->subject.'/', $res->content);
        $this->assertMatchesRegularExpression('/'.$post23->subject.'/', $res->content);
        $this->assertDoesNotMatchRegularExpression('/'.$post31->subject.'/', $res->content);

        // User can search forum posts inside a course.
        $coursecontext = \context_course::instance($course1->id);
        $res = mod_forum_get_tagged_posts($tag, /*$exclusivemode = */false,
            /*$fromctx = */0, /*$ctx = */$coursecontext->id, /*$rec = */1, /*$post = */0);
        $this->assertMatchesRegularExpression('/'.$post11->subject.'/', $res->content);
        $this->assertMatchesRegularExpression('/'.$post12->subject.'/', $res->content);
        $this->assertMatchesRegularExpression('/'.$post13->subject.'/', $res->content);
        $this->assertDoesNotMatchRegularExpression('/'.$post14->subject.'/', $res->content);
        $this->assertMatchesRegularExpression('/'.$post15->subject.'/', $res->content);
        $this->assertMatchesRegularExpression('/'.$post16->subject.'/', $res->content);
        $this->assertDoesNotMatchRegularExpression('/'.$post21->subject.'/', $res->content);
        $this->assertDoesNotMatchRegularExpression('/'.$post22->subject.'/', $res->content);
        $this->assertDoesNotMatchRegularExpression('/'.$post23->subject.'/', $res->content);
        $this->assertEmpty($res->nextpageurl);
    }

    /**
     * Creates an action event.
     *
     * @param int $courseid The course id.
     * @param int $instanceid The instance id.
     * @param string $eventtype The event type.
     * @return bool|calendar_event
     */
    private function create_action_event($courseid, $instanceid, $eventtype) {
        $event = new \stdClass();
        $event->name = 'Calendar event';
        $event->modulename  = 'forum';
        $event->courseid = $courseid;
        $event->instance = $instanceid;
        $event->type = CALENDAR_EVENT_TYPE_ACTION;
        $event->eventtype = $eventtype;
        $event->timestart = time();

        return \calendar_event::create($event);
    }

    /**
     * Test the callback responsible for returning the completion rule descriptions.
     * This function should work given either an instance of the module (cm_info), such as when checking the active rules,
     * or if passed a stdClass of similar structure, such as when checking the the default completion settings for a mod type.
     */
    public function test_mod_forum_completion_get_active_rule_descriptions(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Two activities, both with automatic completion. One has the 'completionsubmit' rule, one doesn't.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 2]);
        $forum1 = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id,
            'completion' => 2,
            'completiondiscussions' => 3,
            'completionreplies' => 3,
            'completionposts' => 3
        ]);
        $forum2 = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id,
            'completion' => 2,
            'completiondiscussions' => 0,
            'completionreplies' => 0,
            'completionposts' => 0
        ]);
        $cm1 = \cm_info::create(get_coursemodule_from_instance('forum', $forum1->id));
        $cm2 = \cm_info::create(get_coursemodule_from_instance('forum', $forum2->id));

        // Data for the stdClass input type.
        // This type of input would occur when checking the default completion rules for an activity type, where we don't have
        // any access to cm_info, rather the input is a stdClass containing completion and customdata attributes, just like cm_info.
        $moddefaults = new \stdClass();
        $moddefaults->customdata = ['customcompletionrules' => [
            'completiondiscussions' => 3,
            'completionreplies' => 3,
            'completionposts' => 3
        ]];
        $moddefaults->completion = 2;

        $activeruledescriptions = [
            get_string('completiondiscussionsdesc', 'forum', 3),
            get_string('completionrepliesdesc', 'forum', 3),
            get_string('completionpostsdesc', 'forum', 3)
        ];
        $this->assertEquals(mod_forum_get_completion_active_rule_descriptions($cm1), $activeruledescriptions);
        $this->assertEquals(mod_forum_get_completion_active_rule_descriptions($cm2), []);
        $this->assertEquals(mod_forum_get_completion_active_rule_descriptions($moddefaults), $activeruledescriptions);
        $this->assertEquals(mod_forum_get_completion_active_rule_descriptions(new \stdClass()), []);
    }

    /**
     * Test the forum_post_is_visible_privately function used in private replies.
     */
    public function test_forum_post_is_visible_privately(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', array('course' => $course->id));
        $context = \context_module::instance($forum->cmid);
        $cm = get_coursemodule_from_instance('forum', $forum->id);

        $author = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $course->id);

        $recipient = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($recipient->id, $course->id);

        $privilegeduser = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($privilegeduser->id, $course->id, 'editingteacher');

        $otheruser = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($otheruser->id, $course->id);

        // Fake a post - this does not need to be persisted to the DB.
        $post = new \stdClass();
        $post->userid = $author->id;
        $post->privatereplyto = $recipient->id;

        // The user is the author.
        $this->setUser($author->id);
        $this->assertTrue(forum_post_is_visible_privately($post, $cm));

        // The user is the intended recipient.
        $this->setUser($recipient->id);
        $this->assertTrue(forum_post_is_visible_privately($post, $cm));

        // The user is not the author or recipient, but does have the readprivatereplies capability.
        $this->setUser($privilegeduser->id);
        $this->assertTrue(forum_post_is_visible_privately($post, $cm));

        // The user is not allowed to view this post.
        $this->setUser($otheruser->id);
        $this->assertFalse(forum_post_is_visible_privately($post, $cm));
    }

    /**
     * An unkown event type should not have any limits
     */
    public function test_mod_forum_core_calendar_get_valid_event_timestart_range_unknown_event(): void {
        global $CFG;
        require_once($CFG->dirroot . "/calendar/lib.php");

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $duedate = time() + DAYSECS;
        $forum = new \stdClass();
        $forum->duedate = $duedate;

        // Create a valid event.
        $event = new \calendar_event([
            'name' => 'Test event',
            'description' => '',
            'format' => 1,
            'courseid' => $course->id,
            'groupid' => 0,
            'userid' => 2,
            'modulename' => 'forum',
            'instance' => 1,
            'eventtype' => FORUM_EVENT_TYPE_DUE . "SOMETHING ELSE",
            'timestart' => 1,
            'timeduration' => 86400,
            'visible' => 1
        ]);

        list ($min, $max) = mod_forum_core_calendar_get_valid_event_timestart_range($event, $forum);
        $this->assertNull($min);
        $this->assertNull($max);
    }

    /**
     * Forums configured without a cutoff date should not have any limits applied.
     */
    public function test_mod_forum_core_calendar_get_valid_event_timestart_range_due_no_limit(): void {
        global $CFG;
        require_once($CFG->dirroot . '/calendar/lib.php');

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $duedate = time() + DAYSECS;
        $forum = new \stdClass();
        $forum->duedate = $duedate;

        // Create a valid event.
        $event = new \calendar_event([
            'name' => 'Test event',
            'description' => '',
            'format' => 1,
            'courseid' => $course->id,
            'groupid' => 0,
            'userid' => 2,
            'modulename' => 'forum',
            'instance' => 1,
            'eventtype' => FORUM_EVENT_TYPE_DUE,
            'timestart' => 1,
            'timeduration' => 86400,
            'visible' => 1
        ]);

        list($min, $max) = mod_forum_core_calendar_get_valid_event_timestart_range($event, $forum);
        $this->assertNull($min);
        $this->assertNull($max);
    }

    /**
     * Forums should be top bound by the cutoff date.
     */
    public function test_mod_forum_core_calendar_get_valid_event_timestart_range_due_with_limits(): void {
        global $CFG;
        require_once($CFG->dirroot . '/calendar/lib.php');

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $duedate = time() + DAYSECS;
        $cutoffdate = $duedate + DAYSECS;
        $forum = new \stdClass();
        $forum->duedate = $duedate;
        $forum->cutoffdate = $cutoffdate;

        // Create a valid event.
        $event = new \calendar_event([
            'name' => 'Test event',
            'description' => '',
            'format' => 1,
            'courseid' => $course->id,
            'groupid' => 0,
            'userid' => 2,
            'modulename' => 'forum',
            'instance' => 1,
            'eventtype' => FORUM_EVENT_TYPE_DUE,
            'timestart' => 1,
            'timeduration' => 86400,
            'visible' => 1
        ]);

        list($min, $max) = mod_forum_core_calendar_get_valid_event_timestart_range($event, $forum);
        $this->assertNull($min);
        $this->assertEquals($cutoffdate, $max[0]);
        $this->assertNotEmpty($max[1]);
    }

    /**
     * An unknown event type should not change the forum instance.
     */
    public function test_mod_forum_core_calendar_event_timestart_updated_unknown_event(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . "/calendar/lib.php");

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $forumgenerator = $generator->get_plugin_generator('mod_forum');
        $duedate = time() + DAYSECS;
        $cutoffdate = $duedate + DAYSECS;
        $forum = $forumgenerator->create_instance(['course' => $course->id]);
        $forum->duedate = $duedate;
        $forum->cutoffdate = $cutoffdate;
        $DB->update_record('forum', $forum);

        // Create a valid event.
        $event = new \calendar_event([
            'name' => 'Test event',
            'description' => '',
            'format' => 1,
            'courseid' => $course->id,
            'groupid' => 0,
            'userid' => 2,
            'modulename' => 'forum',
            'instance' => $forum->id,
            'eventtype' => FORUM_EVENT_TYPE_DUE . "SOMETHING ELSE",
            'timestart' => 1,
            'timeduration' => 86400,
            'visible' => 1
        ]);

        mod_forum_core_calendar_event_timestart_updated($event, $forum);

        $forum = $DB->get_record('forum', ['id' => $forum->id]);
        $this->assertEquals($duedate, $forum->duedate);
        $this->assertEquals($cutoffdate, $forum->cutoffdate);
    }

    /**
     * Due date events should update the forum due date.
     */
    public function test_mod_forum_core_calendar_event_timestart_updated_due_event(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . "/calendar/lib.php");

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $forumgenerator = $generator->get_plugin_generator('mod_forum');
        $duedate = time() + DAYSECS;
        $cutoffdate = $duedate + DAYSECS;
        $newduedate = $duedate + 1;
        $forum = $forumgenerator->create_instance(['course' => $course->id]);
        $forum->duedate = $duedate;
        $forum->cutoffdate = $cutoffdate;
        $DB->update_record('forum', $forum);

        // Create a valid event.
        $event = new \calendar_event([
            'name' => 'Test event',
            'description' => '',
            'format' => 1,
            'courseid' => $course->id,
            'groupid' => 0,
            'userid' => 2,
            'modulename' => 'forum',
            'instance' => $forum->id,
            'eventtype' => FORUM_EVENT_TYPE_DUE,
            'timestart' => $newduedate,
            'timeduration' => 86400,
            'visible' => 1
        ]);

        mod_forum_core_calendar_event_timestart_updated($event, $forum);

        $forum = $DB->get_record('forum', ['id' => $forum->id]);
        $this->assertEquals($newduedate, $forum->duedate);
        $this->assertEquals($cutoffdate, $forum->cutoffdate);
    }

    /**
     * Test forum_get_layout_modes function.
     */
    public function test_forum_get_layout_modes(): void {
        $expectednormal = [
            FORUM_MODE_FLATOLDEST => get_string('modeflatoldestfirst', 'forum'),
            FORUM_MODE_FLATNEWEST => get_string('modeflatnewestfirst', 'forum'),
            FORUM_MODE_THREADED   => get_string('modethreaded', 'forum'),
            FORUM_MODE_NESTED => get_string('modenested', 'forum')
        ];
        $expectedexperimental = [
            FORUM_MODE_FLATOLDEST => get_string('modeflatoldestfirst', 'forum'),
            FORUM_MODE_FLATNEWEST => get_string('modeflatnewestfirst', 'forum'),
            FORUM_MODE_THREADED   => get_string('modethreaded', 'forum'),
            FORUM_MODE_NESTED_V2 => get_string('modenestedv2', 'forum')
        ];

        $this->assertEquals($expectednormal, forum_get_layout_modes());
        $this->assertEquals($expectednormal, forum_get_layout_modes(false));
        $this->assertEquals($expectedexperimental, forum_get_layout_modes(true));
    }

    /**
     * Provides data for tests that cause forum_check_throttling to return early.
     *
     * @return array
     */
    public static function forum_check_throttling_early_returns_provider(): array {
        return [
            'Empty blockafter' => [(object)['id' => 1, 'course' => SITEID, 'blockafter' => 0]],
            'Empty blockperiod' => [(object)['id' => 1, 'course' => SITEID, 'blockafter' => DAYSECS, 'blockperiod' => 0]],
        ];
    }

    /**
     * Tests the early return scenarios of forum_check_throttling.
     *
     * @dataProvider forum_check_throttling_early_returns_provider
     * @covers ::forum_check_throttling
     * @param \stdClass $forum The forum data.
     */
    public function test_forum_check_throttling_early_returns(\stdClass $forum): void {
        $this->assertFalse(forum_check_throttling($forum));
    }

    /**
     * Provides data for tests that cause forum_check_throttling to throw exceptions early.
     *
     * @return array
     */
    public static function forum_check_throttling_early_exceptions_provider(): array {
        return [
            'Non-object forum' => ['a'],
            'Forum ID not set' => [(object)['id' => false]],
            'Course ID not set' => [(object)['id' => 1]],
        ];
    }

    /**
     * Tests the early exception scenarios of forum_check_throttling.
     *
     * @dataProvider forum_check_throttling_early_exceptions_provider
     * @covers ::forum_check_throttling
     * @param mixed $forum The forum data.
     */
    public function test_forum_check_throttling_early_exceptions($forum): void {
        $this->expectException(\coding_exception::class);
        $this->assertFalse(forum_check_throttling($forum));
    }

    /**
     * Tests forum_check_throttling when a non-existent numeric ID is passed for its forum parameter.
     *
     * @covers ::forum_check_throttling
     */
    public function test_forum_check_throttling_nonexistent_numeric_id(): void {
        $this->resetAfterTest();

        $this->expectException(\moodle_exception::class);
        forum_check_throttling(1);
    }

    /**
     * Tests forum_check_throttling when a non-existent forum record is passed for its forum parameter.
     *
     * @covers ::forum_check_throttling
     */
    public function test_forum_check_throttling_nonexistent_forum_cm(): void {
        $this->resetAfterTest();

        $dummyforum = (object)[
            'id' => 1,
            'course' => SITEID,
            'blockafter' => 2,
            'blockperiod' => DAYSECS,
        ];
        $this->expectException(\moodle_exception::class);
        forum_check_throttling($dummyforum);
    }

    /**
     * Tests forum_check_throttling when a user with the 'mod/forum:postwithoutthrottling' capability.
     *
     * @covers ::forum_check_throttling
     */
    public function test_forum_check_throttling_teacher(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'teacher');

        /** @var mod_forum_generator $forumgenerator */
        $forumgenerator = $generator->get_plugin_generator('mod_forum');
        // Forum that limits students from creating more than two posts per day.
        $forum = $forumgenerator->create_instance(
            [
                'course' => $course->id,
                'blockafter' => 2,
                'blockperiod' => DAYSECS,
            ]
        );

        $this->setUser($teacher);
        $discussionrecord = [
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $teacher->id,
        ];
        $discussion = $forumgenerator->create_discussion($discussionrecord);
        // Create a forum post as the teacher.
        $postrecord = [
            'userid' => $teacher->id,
            'discussion' => $discussion->id,
        ];
        $forumgenerator->create_post($postrecord);
        // Create another forum post.
        $forumgenerator->create_post($postrecord);

        $this->assertFalse(forum_check_throttling($forum));
    }

    /**
     * Tests forum_check_throttling for students.
     *
     * @covers ::forum_check_throttling
     */
    public function test_forum_check_throttling_student(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student');

        /** @var mod_forum_generator $forumgenerator */
        $forumgenerator = $generator->get_plugin_generator('mod_forum');
        // Forum that limits students from creating more than two posts per day.
        $forum = $forumgenerator->create_instance(
            [
                'course' => $course->id,
                'blockafter' => 2,
                'blockperiod' => DAYSECS,
                'warnafter' => 1,
            ]
        );

        $this->setUser($student);

        // Student hasn't posted yet so no warning will be shown.
        $throttling = forum_check_throttling($forum);
        $this->assertFalse($throttling);

        // Create a discussion.
        $discussionrecord = [
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $student->id,
        ];
        $discussion = $forumgenerator->create_discussion($discussionrecord);

        // A warning will be shown to the student, but they should still be able to post.
        $throttling = forum_check_throttling($forum);
        $this->assertIsObject($throttling);
        $this->assertTrue($throttling->canpost);

        // Create another forum post as the student.
        $postrecord = [
            'userid' => $student->id,
            'discussion' => $discussion->id,
        ];
        $forumgenerator->create_post($postrecord);

        // Student should now be unable to post after their second post.
        $throttling = forum_check_throttling($forum);
        $this->assertIsObject($throttling);
        $this->assertFalse($throttling->canpost);
    }

    /**
     * Tests forum_count_discussions.
     *
     * @covers ::forum_count_discussions
     */
    public function test_forum_count_discussions(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $forumgenerator = $generator->get_plugin_generator('mod_forum');
        $course1 = $generator->create_course();
        $course2 = $generator->create_course();
        $student = $generator->create_user(['trackforums' => 1]);

        // First forum.
        $forumobj1 = new \stdClass();
        $forumobj1->introformat = FORMAT_HTML;
        $forumobj1->course = $course1->id;
        $forumobj1->trackingtype = FORUM_TRACKING_FORCED;
        $forum1 = $generator->create_module('forum', $forumobj1);
        $forum1cm = get_coursemodule_from_id('forum', $forum1->cmid, 0, false, MUST_EXIST);

        // Second forum.
        $forumobj2 = new \stdClass();
        $forumobj2->introformat = FORMAT_HTML;
        $forumobj2->course = $course2->id;
        $forumobj2->trackingtype = FORUM_TRACKING_OFF;
        $forum2 = $generator->create_module('forum', $forumobj2);
        $forum2cm = get_coursemodule_from_id('forum', $forum2->cmid, 0, false, MUST_EXIST);

        // Third forum.
        $forumobj3 = new \stdClass();
        $forumobj3->introformat = FORMAT_HTML;
        $forumobj3->course = $course2->id;
        $forumobj3->trackingtype = FORUM_TRACKING_OFF;
        $forum3 = $generator->create_module('forum', $forumobj3);
        $forum3cm = get_coursemodule_from_id('forum', $forum3->cmid, 0, false, MUST_EXIST);

        // First make sure there are no discussions for any of the forums.
        $f1discussionscount = forum_count_discussions($forum1, $forum1cm, $course1);
        $this->assertEquals(0, $f1discussionscount);
        $f2discussionscount = forum_count_discussions($forum2, $forum2cm, $course2);
        $this->assertEquals(0, $f2discussionscount);
        $f3discussionscount = forum_count_discussions($forum3, $forum3cm, $course2);
        $this->assertEquals(0, $f3discussionscount);

        // Add 3 discussions to forum 1.
        $discussionobj1 = new \stdClass();
        $discussionobj1->course = $course1->id;
        $discussionobj1->userid = $student->id;
        $discussionobj1->forum = $forum1->id;
        $forumgenerator->create_discussion($discussionobj1);
        $forumgenerator->create_discussion($discussionobj1);
        $forumgenerator->create_discussion($discussionobj1);

        // Make sure there are 3 discussions.
        $f1discussionscount = forum_count_discussions($forum1, $forum1cm, $course1);
        $this->assertEquals(3, $f1discussionscount);

        // Add 4 discussions to forum 2.
        $discussionobj2 = new \stdClass();
        $discussionobj2->course = $course2->id;
        $discussionobj2->userid = $student->id;
        $discussionobj2->forum = $forum2->id;
        $forumgenerator->create_discussion($discussionobj2);
        $forumgenerator->create_discussion($discussionobj2);
        $forumgenerator->create_discussion($discussionobj2);
        $discussion24 = $forumgenerator->create_discussion($discussionobj2);

        // Make sure there are 4 discussions.
        $f2discussionscount = forum_count_discussions($forum2, $forum2cm, $course2);
        $this->assertEquals(4, $f2discussionscount);

        // Delete one discussion from forum 2.
        forum_delete_discussion($discussion24, true, $course2, $forum2cm, $forum2);
        $f2discussionscount = forum_count_discussions($forum2, $forum2cm, $course2);
        $this->assertEquals(3, $f2discussionscount);

        // Make sure there are no discussions.
        $f3discussionscount = forum_count_discussions($forum3, $forum3cm, $course2);
        $this->assertEquals(0, $f3discussionscount);
    }
}
