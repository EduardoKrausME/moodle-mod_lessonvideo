# Video Lesson (`mod_lessonvideo`)

Video Lesson turns one video into a structured Moodle lesson divided into chapters. Each chapter has independent viewing
progress and can contain complementary material such as text, images, PDFs, files, links, questions, and activity
guidance.

The plugin was designed from the concepts used by `mod_videoprogress`, especially validated video tracking, resume
playback, source handling, captions, reporting, gradebook integration, and completion.

## Main features

- Video sources: uploaded file, direct URL/HLS, YouTube, and Vimeo.
- WebVTT captions for HTML5 video sources.
- Chapters positioned by video timecode (`00:00`, `04:32`, `12:54`, and so on).
- Per-chapter viewing percentage and status: not started, in progress, or completed.
- Optional sequential locking: a chapter can require completion before the next chapter is accessible.
- Required or optional chapters.
- Complementary content per chapter: text, image, PDF, generic file, link, question, and activity guidance.
- Complementary content can itself be required for chapter completion.
- Overall lesson progress.
- Completion by all required chapters or by a configured overall percentage.
- Server-side validation of player heartbeats, sequence numbers, playback rate, seek restrictions, and watched
  intervals.
- Resume from the last server-validated position.
- Moodle gradebook integration using overall progress as the grade.
- Moodle custom completion API.
- Per-student/per-chapter progress report with individual reset.
- Privacy API implementation.
- Moodle backup and restore.
- Responsive student interface using Mustache and AMD.

## Requirements

- Moodle 4.5 or later.
- PHP version supported by the target Moodle release.

## Installation

Copy the `lessonvideo` directory to:

`mod/lessonvideo`

Then visit **Site administration > Notifications** and complete the Moodle installation process.

## Teacher workflow

1. Add a **Video Lesson** activity.
2. Select the video source and configure playback and completion rules.
3. Save the activity.
4. Open **Manage lesson**.
5. Add chapters and their start times.
6. Mark chapters as required when appropriate.
7. Enable **Require completion before the next chapter can be accessed** on chapters that must be sequential.
8. Add any complementary content to each chapter and mark required items when appropriate.
9. Use **Progress report** to monitor each student chapter by chapter.

## Chapter completion

A chapter is completed when the student has watched at least the configured chapter percentage and all required
complementary items in that chapter are complete.

The lesson can be configured to complete either when all required chapters are complete or when the overall watched
percentage reaches the configured threshold.

## Tracking model

The browser sends playback observations. The server validates heartbeat sequence, plausible elapsed time, playback rate,
chapter locks, and seek restrictions before merging a segment into the student's watched intervals. Chapter and overall
percentages are recalculated from these validated intervals.

This approach intentionally avoids trusting a percentage calculated only in JavaScript.

## License

GNU GPL v3 or later.
