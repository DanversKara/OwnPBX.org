// SPDX-License-Identifier: GPL-2.0-or-later
@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class, androidx.compose.foundation.ExperimentalFoundationApi::class)

package org.ownpbx.myphone.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.Check
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone

private val palette = listOf(0xFF1A73E8, 0xFFD93025, 0xFF188038, 0xFFE37400, 0xFF9334E6, 0xFF007B83, 0xFFC5221F, 0xFF1967D2)

/** Coloured circle with the last digits of a number, or a tick when selected. */
@Composable
fun Avatar(who: String, selected: Boolean = false) {
    val c = if (selected) MaterialTheme.colorScheme.primary
    else Color(palette[(who.hashCode() and 0x7fffffff) % palette.size])
    Box(Modifier.size(44.dp).clip(CircleShape).background(c), contentAlignment = Alignment.Center) {
        if (selected) Icon(Icons.Filled.Check, "Selected", tint = MaterialTheme.colorScheme.onPrimary)
        else Text(who.filter { it.isLetterOrDigit() }.takeLast(2).ifEmpty { "?" }, color = Color.White,
            style = MaterialTheme.typography.titleMedium)
    }
}

private fun parseUtc(ts: String): Date? = try {
    SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).apply { timeZone = TimeZone.getTimeZone("UTC") }.parse(ts)
} catch (e: Exception) { null }

/** "14:05" today, "Mon" this week, "Oct 3" older. Server times are UTC. */
fun shortTime(ts: String, utc: Boolean = true): String {
    val d = (if (utc) parseUtc(ts) else try {
        SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).parse(ts)
    } catch (e: Exception) { null }) ?: return ts
    val age = System.currentTimeMillis() - d.time
    val fmt = when {
        age < 20 * 3600_000L && SimpleDateFormat("yyyyMMdd", Locale.getDefault()).let { it.format(d) == it.format(Date()) } -> "HH:mm"
        age < 6 * 86400_000L -> "EEE"
        else -> "MMM d"
    }
    return SimpleDateFormat(fmt, Locale.getDefault()).format(d)
}

fun duration(sec: Int?): String {
    val s = sec ?: 0
    return if (s >= 60) "${s / 60}m ${s % 60}s" else "${s}s"
}

/** Pretty-print 10-digit North American numbers; leave extensions alone. */
fun prettyNumber(n: String): String {
    val d = n.filter { it.isDigit() }.let { if (it.length == 11 && it.startsWith("1")) it.drop(1) else it }
    return if (d.length == 10) "(${d.take(3)}) ${d.substring(3, 6)}-${d.takeLast(4)}" else n
}



/** Ring colour for a presence: green online / on a call; red DND / recent / offline; none hidden. */
fun presenceColor(presence: String): Color? =
    when (presence) {
        "online", "oncall" -> Color(0xFF22C55E)
        "dnd", "recent", "offline" -> Color(0xFFEF4444)
        else -> null
    }

fun presenceLabel(presence: String): String =
    when (presence) {
        "online" -> "Online"
        "oncall" -> "Online / On a call"
        "dnd" -> "Do Not Disturb"
        "recent" -> "Recently online (last 30 minutes)"
        "offline" -> "Offline"
        "hidden" -> "Status hidden"
        else -> ""
    }

/** Decoded contact photos by server path (the path carries a version, so entries never go stale). */
private val photoCache = android.util.LruCache<String, androidx.compose.ui.graphics.ImageBitmap>(60)

/**
 * Round contact picture (their photo, or initials) inside the presence ring: solid green online,
 * dotted green with a phone on a call, red with a slash for Do Not Disturb, dotted red recently
 * online, solid red offline, none when hidden or an outside number.
 */
@Composable
fun ContactAvatar(
    model: AppModel,
    who: String,
    photoUrl: String,
    presence: String = "",
    size: androidx.compose.ui.unit.Dp = 44.dp,
) {
    var img by remember(photoUrl) { mutableStateOf(if (photoUrl.isBlank()) null else photoCache.get(photoUrl)) }
    LaunchedEffect(photoUrl) {
        if (photoUrl.isBlank() || img != null) return@LaunchedEffect
        try {
            val bytes = model.api().photoBytes(photoUrl)
            val bmp = kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.Default) {
                android.graphics.BitmapFactory.decodeByteArray(bytes, 0, bytes.size)?.asImageBitmap()
            }
            if (bmp != null) {
                photoCache.put(photoUrl, bmp)
                img = bmp
            }
        } catch (_: Exception) {}
    }
    val ring = presenceColor(presence)
    val dotted = presence == "oncall" || presence == "recent"
    val slash = presence == "dnd"
    val stroke = if (size > 50.dp) 4.dp else 3.dp
    Box(
        Modifier.size(size + stroke * 2 + 2.dp)
            .drawWithContent {
                drawContent()
                if (ring != null) {
                    val w = stroke.toPx()
                    val r = this.size.minDimension / 2 - w / 2
                    drawCircle(
                        ring,
                        radius = r,
                        style = androidx.compose.ui.graphics.drawscope.Stroke(
                            width = w,
                            pathEffect = if (dotted)
                                androidx.compose.ui.graphics.PathEffect.dashPathEffect(floatArrayOf(w * 0.9f, w * 1.1f))
                            else null,
                            cap = if (dotted) androidx.compose.ui.graphics.StrokeCap.Round
                                else androidx.compose.ui.graphics.StrokeCap.Butt,
                        ),
                    )
                    if (slash) {
                        // The "no entry" slash, corner to corner inside the ring.
                        val c = center
                        val d = r * 0.7071f
                        drawLine(ring, androidx.compose.ui.geometry.Offset(c.x - d, c.y - d),
                            androidx.compose.ui.geometry.Offset(c.x + d, c.y + d), strokeWidth = w,
                            cap = androidx.compose.ui.graphics.StrokeCap.Round)
                    }
                }
            },
        contentAlignment = Alignment.Center,
    ) {
        val bmp = img
        if (bmp != null)
            androidx.compose.foundation.Image(
                bmp,
                presenceLabel(presence).ifBlank { null },
                Modifier.size(size).clip(CircleShape),
                contentScale = androidx.compose.ui.layout.ContentScale.Crop,
            )
        else
            Box(Modifier.size(size).clip(CircleShape)
                .background(Color(palette[(who.hashCode() and 0x7fffffff) % palette.size])),
                contentAlignment = Alignment.Center) {
                Text(who.filter { it.isLetterOrDigit() }.takeLast(2).ifEmpty { "?" }, color = Color.White,
                    style = if (size > 50.dp) MaterialTheme.typography.headlineSmall else MaterialTheme.typography.titleMedium)
            }
        if (presence == "oncall")
            Box(Modifier.size(size).clip(CircleShape).background(Color.Black.copy(alpha = 0.45f)),
                contentAlignment = Alignment.Center) {
                Icon(androidx.compose.material.icons.Icons.Filled.Call, "On a call", tint = Color.White,
                    modifier = Modifier.size(size * 0.45f))
            }
    }
}
