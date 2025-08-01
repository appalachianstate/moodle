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

namespace core_ai\aiactions\responses;

/**
 * Generate image action response class.
 *
 * Any method that processes an action must return an instance of this class.
 *
 * @package    core_ai
 * @copyright  2024 Matt Porritt <matt.porritt@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class response_generate_image extends response_base {
    /** @var \stored_file|null The URL of the generated image. */
    private ?\stored_file $draftfile = null;

    /** @var string|null The revised prompt generated by the AI. */
    private ?string $revisedprompt = null;

    /** @var string|null The URL of the source image used to generate the image. */
    private ?string $sourceurl = null;

    /**
     * Constructor.
     *
     * @param bool $success The success status of the action.
     * @param int $errorcode Error code. Must exist if success is false.
     * @param string $error Error name. Must exist if success is false
     * @param string $errormessage Error message.
     */
    public function __construct(
        bool $success,
        int $errorcode = 0,
        string $error = '',
        string $errormessage = '',
    ) {
        parent::__construct(
            success: $success,
            actionname: 'generate_image',
            errorcode: $errorcode,
            error: $error,
            errormessage: $errormessage,
        );
    }

    #[\Override]
    public function set_response_data(array $response): void {
        $this->draftfile = $response['draftfile'] ?? null;
        $this->revisedprompt = $response['revisedprompt'] ?? null;
        $this->sourceurl = $response['sourceurl'] ?? null;
        $this->model = $response['model'] ?? null;
    }

    #[\Override]
    public function get_response_data(): array {
        return [
            'draftfile' => $this->draftfile,
            'revisedprompt' => $this->revisedprompt,
            'sourceurl' => $this->sourceurl,
            'model' => $this->model,
        ];
    }
}
