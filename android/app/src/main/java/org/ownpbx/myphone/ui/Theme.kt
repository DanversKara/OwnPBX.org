// SPDX-License-Identifier: GPL-2.0-or-later
@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class, androidx.compose.foundation.ExperimentalFoundationApi::class)

package org.ownpbx.myphone.ui

import android.os.Build
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.ColorScheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.dynamicDarkColorScheme
import androidx.compose.material3.dynamicLightColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.compositeOver
import androidx.compose.ui.graphics.lerp
import androidx.compose.ui.graphics.luminance
import androidx.compose.ui.platform.LocalContext
import org.ownpbx.myphone.data.PanelTheme

private val Blue = Color(0xFF1A73E8)

/**
 * The app's colours. By default they follow the panel theme this user sees on the web (their My
 * theme preset, else the site's Branding: header colour, button colour, Frosted or Wet glass).
 * Settings -> App colours can switch to the phone's own (Material You) instead. Light / dark
 * follows the phone.
 */
object AppTheme {
    /** null = not known yet (first start): Material You until the PBX answers. */
    var panel by mutableStateOf<PanelTheme?>(null)
    /** "panel" (match the web panel) or "phone" (Material You). */
    var mode by mutableStateOf("panel")
}

private fun hex(s: String, fallback: Color): Color =
    try {
        Color(android.graphics.Color.parseColor(s))
    } catch (_: Exception) {
        fallback
    }

/** Readable on a dark background: lift very dark brand colours toward white. */
private fun forDark(c: Color): Color = if (c.luminance() < 0.18f) lerp(c, Color.White, 0.55f) else c

/** Readable on a light background: pull very light brand colours toward black. */
private fun forLight(c: Color): Color = if (c.luminance() > 0.6f) lerp(c, Color.Black, 0.45f) else c

private fun on(c: Color) = if (c.luminance() > 0.45f) Color(0xFF111111) else Color.White

fun panelScheme(t: PanelTheme, dark: Boolean): ColorScheme {
    val header = hex(t.header, Color(0xFF1A1A2E))
    // An accent equal to the default navy is too dark to read in dark mode.
    val accentRaw = hex(t.accent, header)
    val wet = t.look == "wet"
    return if (dark) {
        val primary = forDark(accentRaw)
        // Wet glass: deeper water-tinted surfaces; frosted: a light tint.
        val base = Color(0xFF0E1117)
        val bg = lerp(base, header, if (wet) 0.32f else 0.14f)
        val surface = lerp(base, header, if (wet) 0.40f else 0.18f)
        val variant = lerp(surface, primary, if (wet) 0.22f else 0.12f)
        darkColorScheme(
            primary = primary,
            onPrimary = on(primary),
            primaryContainer = lerp(bg, primary, 0.35f),
            onPrimaryContainer = Color.White,
            secondary = lerp(primary, Color.White, 0.25f),
            secondaryContainer = lerp(bg, primary, 0.25f),
            onSecondaryContainer = Color.White,
            tertiaryContainer = lerp(bg, primary, 0.45f),
            background = bg,
            surface = surface,
            surfaceVariant = variant,
            surfaceContainer = lerp(surface, Color.White, 0.04f),
            surfaceContainerHigh = lerp(surface, Color.White, 0.07f),
            surfaceContainerHighest = lerp(surface, Color.White, 0.10f),
            surfaceContainerLow = lerp(surface, Color.Black, 0.10f),
            onSurfaceVariant = Color(0xFFC3CFDB),
            outline = Color.White.copy(alpha = if (wet) 0.34f else 0.22f).compositeOver(surface),
        )
    } else {
        val primary = forLight(accentRaw)
        val base = Color(0xFFF8FAFC)
        val bg = lerp(base, header, if (wet) 0.10f else 0.04f)
        val surface = lerp(base, accentRaw, if (wet) 0.06f else 0.02f)
        lightColorScheme(
            primary = primary,
            onPrimary = on(primary),
            primaryContainer = lerp(Color.White, primary, 0.22f),
            onPrimaryContainer = Color(0xFF111111),
            secondary = lerp(primary, Color.Black, 0.15f),
            secondaryContainer = lerp(Color.White, primary, 0.16f),
            tertiaryContainer = lerp(Color.White, primary, 0.30f),
            background = bg,
            surface = surface,
            surfaceVariant = lerp(Color.White, primary, if (wet) 0.14f else 0.08f),
            surfaceContainer = lerp(Color.White, header, 0.05f),
            surfaceContainerHigh = lerp(Color.White, header, 0.08f),
            surfaceContainerHighest = lerp(Color.White, header, 0.11f),
            surfaceContainerLow = lerp(Color.White, header, 0.03f),
        )
    }
}

@Composable
fun MyPhoneTheme(content: @Composable () -> Unit) {
    val dark = isSystemInDarkTheme()
    val ctx = LocalContext.current
    val panel = AppTheme.panel
    val scheme =
        when {
            AppTheme.mode == "panel" && panel != null -> panelScheme(panel, dark)
            Build.VERSION.SDK_INT >= 31 -> if (dark) dynamicDarkColorScheme(ctx) else dynamicLightColorScheme(ctx)
            dark -> darkColorScheme(primary = Color(0xFF8AB4F8))
            else -> lightColorScheme(primary = Blue)
        }
    MaterialTheme(colorScheme = scheme, content = content)
}
