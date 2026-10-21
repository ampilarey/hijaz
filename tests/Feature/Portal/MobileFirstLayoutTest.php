<?php

namespace Tests\Feature\Portal;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The portal is written phone-first, so nothing may correct it at phone width.
 */
class MobileFirstLayoutTest extends TestCase
{
    private function theme(): string
    {
        return File::get(resource_path('css/filament/portal/theme.css'));
    }

    public function test_the_theme_never_overrides_a_layout_below_a_width(): void
    {
        $this->assertDoesNotMatchRegularExpression('/@media[^{]*max-width/', $this->theme(),
            'Write the phone case as the default and widen it with min-width.');
    }

    public function test_a_touch_screen_gets_big_targets_and_sixteen_pixel_fields(): void
    {
        $this->assertMatchesRegularExpression('/@media\s*\(pointer:\s*coarse\)\s*\{.*?min-height:\s*44px/s', $this->theme());
        $this->assertMatchesRegularExpression('/@media\s*\(pointer:\s*coarse\)\s*\{.*?input, select, textarea\s*\{\s*font-size:\s*16px/s', $this->theme());
    }

    public function test_the_bottom_bar_hides_once_the_sidebar_is_on_screen(): void
    {
        $this->assertMatchesRegularExpression('/@media\s*\(min-width:[^)]+\)\s*\{\s*\.hz-bottom-nav\s*\{\s*display:\s*none/s', $this->theme());
    }

    public function test_row_actions_are_icons_until_there_is_room_for_the_word(): void
    {
        $theme = $this->theme();

        $this->assertMatchesRegularExpression('/\.fi-ta-actions \.fi-link:has\(\.fi-icon\) \.fi-link-label,\s*\.fi-ta-actions \.fi-btn:has\(\.fi-icon\) \.fi-btn-label\s*\{\s*display:\s*none/s', $theme,
            'The label is hidden by default and shown from a width, never the other way round.');
        $this->assertMatchesRegularExpression('/@media\s*\(min-width:[^)]+\)\s*\{\s*\.fi-ta-actions[^{]*\{\s*display:\s*inline/s', $theme);
    }

    public function test_today_rows_are_thumb_sized(): void
    {
        $this->assertMatchesRegularExpression('/\.hz-row\s*\{[^}]*min-height:\s*44px/s', $this->theme());
    }

    public function test_the_theme_is_built_and_committed(): void
    {
        $manifest = json_decode(File::get(public_path('build/manifest.json')), true);

        $this->assertArrayHasKey('resources/css/filament/portal/theme.css', $manifest);
        $this->assertFileExists(public_path('build/'.$manifest['resources/css/filament/portal/theme.css']['file']));
    }
}
