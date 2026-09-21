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

namespace mod_videolesson;

/**
 * Tests timecode parsing and formatting.
 *
 * @package mod_videolesson
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class timecode_test extends \advanced_testcase {

    /**
     * Method test_parse_supported_formats.
     *
     * @return void Return value.
     */
    public function test_parse_supported_formats(): void {
        $this->assertEquals(90.0, timecode::parse('90'));
        $this->assertEquals(272.0, timecode::parse('04:32'));
        $this->assertEquals(3890.0, timecode::parse('1:04:50'));
        $this->assertNull(timecode::parse('4:99'));
    }

    /**
     * Method test_format.
     *
     * @return void Return value.
     */
    public function test_format(): void {
        $this->assertSame('04:32', timecode::format(272));
        $this->assertSame('01:04:50', timecode::format(3890));
    }
}
