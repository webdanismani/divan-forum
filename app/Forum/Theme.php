<?php

namespace App\Forum;

/**
 * Board colours chosen in the admin panel.
 *
 * The stylesheet defines every colour as a CSS custom property; this class
 * stores a handful of them as a board option and turns them into a small
 * <style> block that overrides the defaults. Only strict #rrggbb values are
 * ever stored or printed, so the generated CSS is safe to output unescaped.
 */
class Theme
{
    protected const KEY = 'theme.colors';

    public function __construct(protected Settings $settings) {}

    /** @return array<string, array{label: string, hint: string}> */
    public function fields(): array
    {
        return [
            'accent' => ['label' => 'Accent colour', 'hint' => 'Buttons, links and active markers.'],
            'header' => ['label' => 'Header', 'hint' => 'Top bar with the logo, and the footer.'],
            'navbar' => ['label' => 'Menu bar', 'hint' => 'The navigation strip under the header.'],
            'bar' => ['label' => 'Title bars', 'hint' => 'Headings of tables and boxes.'],
            'category' => ['label' => 'Category bars', 'hint' => 'Category rows in the forum list.'],
            'background' => ['label' => 'Page background', 'hint' => 'Used in the light theme only.'],
        ];
    }

    /** @return array<string, string> */
    public function defaults(): array
    {
        return $this->presets()['Classic navy'];
    }

    /** @return array<string, array<string, string>> */
    public function presets(): array
    {
        $make = fn (string ...$c) => array_combine(array_keys($this->fields()), $c);

        return [
            'Classic navy' => $make('#1877d4', '#24334f', '#2d4063', '#2d4063', '#3a4f78', '#e9ecf1'),
            'Anthracite' => $make('#e8590c', '#22262d', '#2c313a', '#2c313a', '#3b414c', '#eceef1'),
            'Emerald' => $make('#1f9d6b', '#17382f', '#1f4a3e', '#1f4a3e', '#2a6050', '#eaf0ed'),
            'Burgundy' => $make('#c0392b', '#4a1620', '#5e1d2a', '#5e1d2a', '#762636', '#f1ecec'),
            'Purple' => $make('#7b52c7', '#2e2350', '#3b2d66', '#3b2d66', '#4b3a80', '#eeecf4'),
            'Ocean' => $make('#0d9bd8', '#0f3a57', '#15496c', '#15496c', '#1c5f8a', '#e8eef2'),
            'Light' => $make('#1877d4', '#ffffff', '#f2f4f7', '#2b3340', '#3b4656', '#eef0f3'),
        ];
    }

    /** Stored colours, validated and completed with the defaults. */
    public function colors(): array
    {
        $stored = array_filter((array) $this->settings->get(self::KEY), fn ($value) => self::isHex($value));

        return array_merge($this->defaults(), array_intersect_key($stored, $this->fields()));
    }

    public function isCustom(): bool
    {
        return $this->colors() !== $this->defaults();
    }

    public function save(array $colors): void
    {
        $clean = [];
        foreach (array_keys($this->fields()) as $key) {
            if (self::isHex($colors[$key] ?? null)) {
                $clean[$key] = strtolower($colors[$key]);
            }
        }

        $this->settings->set([self::KEY => $clean]);
    }

    public function reset(): void
    {
        $this->settings->set([self::KEY => null]);
    }

    /** CSS that overrides the stylesheet's colour tokens; empty when the defaults are in use. */
    public function css(): string
    {
        if (! $this->isCustom()) {
            return '';
        }

        $c = $this->colors();
        $accent = $c['accent'];

        $shared = "--accent-base:$accent;--on-accent-base:".self::contrast($accent).";--header:{$c['header']};--on-header:".self::contrast($c['header']).';'
            ."--header-2:{$c['navbar']};--on-nav:".self::contrast($c['navbar']).';'
            ."--bar:{$c['bar']};--bar-text:".self::contrast($c['bar']).';'
            .'--bar-muted:color-mix(in srgb,'.self::contrast($c['bar']).' 70%,transparent);'
            ."--bar-2:{$c['category']};--cat-text:".self::contrast($c['category']).';';

        $light = "--accent:$accent;--accent-hover:color-mix(in srgb,$accent 82%,#000);"
            ."--accent-soft:color-mix(in srgb,$accent 11%,#fff);--on-accent:".self::contrast($accent).';'
            ."--bg:{$c['background']};";

        // In the dark theme the accent is lightened so that it stays readable on dark surfaces.
        $dark = "--accent:color-mix(in srgb,$accent 66%,#fff);--accent-hover:color-mix(in srgb,$accent 48%,#fff);"
            ."--accent-soft:color-mix(in srgb,$accent 24%,#161b24);--on-accent:#0b1622;";

        return ":root{{$light}{$shared}}"
            .":root[data-theme=\"dark\"]{{$dark}{$shared}}"
            ."@media (prefers-color-scheme:dark){:root[data-theme=\"auto\"]{{$dark}{$shared}}}";
    }

    /** White or near-black, whichever is readable on the given background. */
    public static function contrast(string $hex): string
    {
        [$r, $g, $b] = array_map(function (string $part) {
            $value = hexdec($part) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) > 0.42 ? '#1f2733' : '#ffffff';
    }

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }
}
