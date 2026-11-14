<?php

namespace App\Http\Controllers\Settings;

use App\Enums\TextSize;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

/**
 * Saves the text size to the user's account so it follows them to any
 * computer, and to a cookie so the login page uses it too.
 */
class TextSizeController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'text_size' => ['required', Rule::enum(TextSize::class)],
        ]);

        $request->user()?->forceFill(['text_size' => $data['text_size']])->saveQuietly();

        Cookie::queue(TextSize::COOKIE, $data['text_size'], 60 * 24 * 365);

        return back();
    }
}
