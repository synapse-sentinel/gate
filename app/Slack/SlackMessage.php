<?php

declare(strict_types=1);

namespace App\Slack;

/**
 * Fluent builder for Slack Block Kit messages.
 *
 * Uses modern Block Kit patterns with attachments for color coding.
 */
final class SlackMessage
{
    /** @var array<int, array<string, mixed>> */
    private array $blocks = [];

    /** @var array<int, array<string, mixed>> */
    private array $attachments = [];

    private string $fallbackText = '';

    private ?string $attachmentColor = null;

    public static function create(): self
    {
        return new self;
    }

    public function fallback(string $text): self
    {
        $this->fallbackText = $text;

        return $this;
    }

    public function header(string $text): self
    {
        $this->blocks[] = [
            'type' => 'header',
            'text' => [
                'type' => 'plain_text',
                'text' => $text,
                'emoji' => true,
            ],
        ];

        return $this;
    }

    public function section(string $markdown): self
    {
        $this->blocks[] = [
            'type' => 'section',
            'text' => [
                'type' => 'mrkdwn',
                'text' => $markdown,
            ],
        ];

        return $this;
    }

    public function sectionWithAccessory(string $markdown, array $accessory): self
    {
        $this->blocks[] = [
            'type' => 'section',
            'text' => [
                'type' => 'mrkdwn',
                'text' => $markdown,
            ],
            'accessory' => $accessory,
        ];

        return $this;
    }

    /**
     * Add a two-column fields section.
     *
     * @param  array<int, array{label: string, value: string}>  $fields
     */
    public function fields(array $fields): self
    {
        $formattedFields = [];
        foreach ($fields as $field) {
            $formattedFields[] = [
                'type' => 'mrkdwn',
                'text' => "*{$field['label']}*\n{$field['value']}",
            ];
        }

        $this->blocks[] = [
            'type' => 'section',
            'fields' => $formattedFields,
        ];

        return $this;
    }

    public function divider(): self
    {
        $this->blocks[] = ['type' => 'divider'];

        return $this;
    }

    /**
     * Add a context block with muted text/images.
     *
     * @param  array<int, string>  $elements  Array of markdown strings
     */
    public function context(array $elements): self
    {
        $formattedElements = [];
        foreach ($elements as $element) {
            $formattedElements[] = [
                'type' => 'mrkdwn',
                'text' => $element,
            ];
        }

        $this->blocks[] = [
            'type' => 'context',
            'elements' => $formattedElements,
        ];

        return $this;
    }

    /**
     * Add action buttons.
     *
     * @param  array<int, array{text: string, url?: string, style?: string, action_id?: string, value?: string}>  $buttons
     */
    public function actions(array $buttons): self
    {
        $elements = [];
        foreach ($buttons as $button) {
            $element = [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => $button['text'],
                    'emoji' => true,
                ],
            ];

            if (isset($button['url'])) {
                $element['url'] = $button['url'];
            }

            if (isset($button['style'])) {
                $element['style'] = $button['style'];
            }

            if (isset($button['action_id'])) {
                $element['action_id'] = $button['action_id'];
            } else {
                $element['action_id'] = 'action_'.md5($button['text'].microtime());
            }

            if (isset($button['value'])) {
                $element['value'] = $button['value'];
            }

            $elements[] = $element;
        }

        $this->blocks[] = [
            'type' => 'actions',
            'elements' => $elements,
        ];

        return $this;
    }

    /**
     * Set the color bar on the left side.
     *
     * Common values: #36a64f (green), #ff0000 (red), #ffcc00 (yellow)
     */
    public function color(string $hex): self
    {
        $this->attachmentColor = $hex;

        return $this;
    }

    /**
     * Format a Unix timestamp for Slack's native date rendering.
     *
     * @param  string  $format  Slack date format tokens like {date_short_pretty}, {time}
     * @param  string  $fallback  Fallback text for clients that don't support date formatting
     */
    public static function timestamp(int $unixTimestamp, string $format = '{date_short_pretty} at {time}', ?string $fallback = null): string
    {
        $fallback ??= date('M j, Y g:i A', $unixTimestamp);

        return "<!date^{$unixTimestamp}^{$format}|{$fallback}>";
    }

    /**
     * Convert to Slack API payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'text' => $this->fallbackText,
        ];

        // If we have a color, wrap blocks in an attachment
        if ($this->attachmentColor !== null) {
            $payload['attachments'] = [
                [
                    'color' => $this->attachmentColor,
                    'blocks' => $this->blocks,
                ],
            ];
        } else {
            $payload['blocks'] = $this->blocks;
        }

        // Add any additional attachments
        if (! empty($this->attachments)) {
            $payload['attachments'] = array_merge(
                $payload['attachments'] ?? [],
                $this->attachments
            );
        }

        return $payload;
    }

    /**
     * Convert to JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Get the blocks array (for testing).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBlocks(): array
    {
        return $this->blocks;
    }
}
