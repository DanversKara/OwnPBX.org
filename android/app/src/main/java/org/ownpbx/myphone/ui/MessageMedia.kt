// SPDX-License-Identifier: GPL-2.0-or-later
package org.ownpbx.myphone.ui

import android.content.ContentValues
import android.content.Context
import android.graphics.ImageDecoder
import android.graphics.drawable.AnimatedImageDrawable
import android.graphics.drawable.BitmapDrawable
import android.graphics.drawable.Drawable
import android.net.Uri
import android.os.Build
import android.provider.MediaStore
import android.provider.OpenableColumns
import android.widget.ImageView
import android.widget.MediaController
import android.widget.VideoView
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.BrokenImage
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Download
import androidx.compose.material.icons.filled.PlayCircle
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.ownpbx.myphone.data.Attachment
import org.ownpbx.myphone.data.MediaItem
import java.io.File

/** Up to 3 per message; 25 MB each (the PBX shrinks photos for phone numbers). */
const val MAX_ATTACH = 3
const val MAX_ATTACH_BYTES = 25 * 1024 * 1024

val EMOJI = ("😀 😃 😄 😁 😆 😅 😂 🤣 😊 😇 🙂 😉 😍 🥰 😘 😋 😜 🤪 😎 🤩 🥳 😏 😒 😔 😢 😭 😤 😠 😡 🤯 😳 🥺 😱 " +
    "😴 🤔 🤗 🤭 🤫 🙄 😬 🤐 🤢 🤮 🤧 😷 🤒 🤕 👍 👎 👌 ✌️ 🤞 🤟 🤙 👏 🙌 🙏 💪 👋 🤝 ✋ 👀 💯 🔥 ✨ " +
    "🎉 🎊 🎂 🎁 ❤️ 🧡 💛 💚 💙 💜 🖤 🤍 💔 💕 💖 ⭐ 🌟 ☀️ 🌙 ⚡ ☔ ❄️ 🌈 🌹 🌻 🍀 🍕 🍔 🍟 🌮 🍩 🍪 " +
    "☕ 🍺 🍷 🥂 🚗 ✈️ 🏠 📞 📱 💻 📷 🎵 ⚽ 🏀 🐶 🐱 🦄 👻 💩 ✅ ❌ ❓ ❗ ⏰ 📍").split(" ")

/** Emoji grid under the text box; tapping one adds it. */
@Composable
fun EmojiPanel(onPick: (String) -> Unit) {
    LazyVerticalGrid(GridCells.Adaptive(44.dp), Modifier.fillMaxWidth().heightIn(max = 220.dp).padding(horizontal = 8.dp)) {
        items(EMOJI) { e ->
            Box(Modifier.size(44.dp).clip(RoundedCornerShape(8.dp)).clickable { onPick(e) }, contentAlignment = Alignment.Center) {
                Text(e, fontSize = 24.sp)
            }
        }
    }
}

/** Read a picked photo/video into memory (null + a reason when it can't be sent). */
fun readAttachment(ctx: Context, uri: Uri): Pair<Attachment?, String?> {
    val cr = ctx.contentResolver
    var name = "media"
    var size = -1L
    cr.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE), null, null, null)?.use { c ->
        if (c.moveToFirst()) {
            name = c.getString(0) ?: name
            if (!c.isNull(1)) size = c.getLong(1)
        }
    }
    if (size > MAX_ATTACH_BYTES) return null to "$name is over 25 MB."
    val mime = cr.getType(uri) ?: "application/octet-stream"
    val bytes = cr.openInputStream(uri)?.use { it.readBytes() } ?: return null to "Couldn't open $name."
    if (bytes.size > MAX_ATTACH_BYTES) return null to "$name is over 25 MB."
    return Attachment(bytes, mime, name) to null
}

private fun decode(file: File): Drawable? =
    try {
        if (Build.VERSION.SDK_INT >= 28) {
            ImageDecoder.decodeDrawable(ImageDecoder.createSource(file)) { d, info, _ ->
                // Keep big photos light; GIFs are small anyway.
                val max = 1600
                val w = info.size.width; val h = info.size.height
                if (w > max || h > max) {
                    val s = max.toFloat() / maxOf(w, h)
                    d.setTargetSize((w * s).toInt().coerceAtLeast(1), (h * s).toInt().coerceAtLeast(1))
                }
            }
        } else {
            val o = android.graphics.BitmapFactory.Options().apply { inSampleSize = 1 }
            android.graphics.BitmapFactory.decodeFile(file.path, o)?.let { BitmapDrawable(null, it) }
        }
    } catch (_: Exception) { null }

/** Photo or GIF (animated on Android 9+). */
@Composable
fun MediaImage(file: File, modifier: Modifier) {
    var d by remember(file) { mutableStateOf<Drawable?>(null) }
    var failed by remember(file) { mutableStateOf(false) }
    LaunchedEffect(file) {
        d = withContext(Dispatchers.IO) { decode(file) }
        failed = d == null
    }
    val dr = d
    when {
        dr != null -> AndroidView(
            factory = { c -> ImageView(c).apply { adjustViewBounds = true; scaleType = ImageView.ScaleType.FIT_CENTER } },
            update = { v ->
                v.setImageDrawable(dr)
                if (Build.VERSION.SDK_INT >= 28 && dr is AnimatedImageDrawable) dr.start()
            },
            modifier = modifier,
        )
        failed -> Icon(Icons.Filled.BrokenImage, "Couldn't show this picture", modifier.size(48.dp))
        else -> Box(modifier.size(160.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(24.dp)) }
    }
}

/** One attachment in a bubble: a picture, a GIF or a video (tap to open full screen). */
@Composable
@OptIn(androidx.compose.foundation.ExperimentalFoundationApi::class)
fun MediaBubble(model: AppModel, item: MediaItem, onLong: () -> Unit = {}, onOpen: (File) -> Unit) {
    val ctx = LocalContext.current
    var file by remember(item.url) { mutableStateOf<File?>(null) }
    var loading by remember(item.url) { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    fun fetch(then: (File) -> Unit = {}) {
        if (loading) return
        file?.let { then(it); return }
        loading = true
        scope.launch {
            model.guardQuiet { it.mediaFile(item.url, ctx.cacheDir) }?.let { file = it; then(it) }
            loading = false
        }
    }
    // Pictures load straight away; videos only when tapped (they can be big).
    LaunchedEffect(item.url) { if (item.kind == "image" || item.kind == "gif") fetch() }
    val shape = RoundedCornerShape(12.dp)
    when (item.kind) {
        "image", "gif" -> {
            val f = file
            Box(Modifier.padding(top = 4.dp).widthIn(max = 260.dp).heightIn(max = 300.dp).clip(shape)
                .combinedClickable(onLongClick = onLong) { f?.let(onOpen) }) {
                if (f != null) MediaImage(f, Modifier.widthIn(max = 260.dp).heightIn(max = 300.dp))
                else Box(Modifier.size(160.dp).background(Color.Black.copy(alpha = 0.15f)), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(Modifier.size(24.dp))
                }
                if (item.kind == "gif") Text("GIF", Modifier.align(Alignment.BottomStart).padding(6.dp)
                    .background(Color.Black.copy(alpha = 0.5f), RoundedCornerShape(4.dp)).padding(horizontal = 4.dp),
                    color = Color.White, fontSize = 11.sp)
            }
        }
        "video" -> Box(Modifier.padding(top = 4.dp).size(220.dp, 140.dp).clip(shape).background(Color.Black)
            .combinedClickable(onLongClick = onLong) { fetch(onOpen) }, contentAlignment = Alignment.Center) {
            if (loading) CircularProgressIndicator(color = Color.White)
            else Icon(Icons.Filled.PlayCircle, "Play video", tint = Color.White, modifier = Modifier.size(56.dp))
        }
        else -> TextButton({ fetch(onOpen) }) { Text("📎 ${item.name}") }
    }
}

/** Full-screen picture / GIF / video with a Save button. */
@Composable
fun MediaViewer(file: File, kind: String, onSave: () -> Unit, onClose: () -> Unit) {
    Dialog(onClose, DialogProperties(usePlatformDefaultWidth = false)) {
        Box(Modifier.fillMaxSize().background(Color.Black)) {
            if (kind == "video") AndroidView(
                factory = { c ->
                    VideoView(c).apply {
                        setMediaController(MediaController(c).also { it.setAnchorView(this) })
                        setVideoPath(file.path)
                        setOnPreparedListener { start() }
                    }
                },
                modifier = Modifier.fillMaxSize().align(Alignment.Center),
            ) else MediaImage(file, Modifier.fillMaxSize().align(Alignment.Center))
            Row(Modifier.align(Alignment.TopEnd).statusBarsPadding().padding(8.dp)) {
                IconButton(onSave) { Icon(Icons.Filled.Download, "Save to Downloads", tint = Color.White) }
                IconButton(onClose) { Icon(Icons.Filled.Close, "Close", tint = Color.White) }
            }
        }
    }
}

/** Copy a downloaded attachment to the phone's Downloads (Download/OwnPBX). Returns where it went. */
suspend fun saveToDownloads(ctx: Context, file: File, mime: String): String =
    withContext(Dispatchers.IO) {
        val name = "ownpbx-" + file.name.takeLast(20)
        if (Build.VERSION.SDK_INT >= 29) {
            val v = ContentValues().apply {
                put(MediaStore.Downloads.DISPLAY_NAME, name)
                put(MediaStore.Downloads.MIME_TYPE, mime.ifBlank { "application/octet-stream" })
                put(MediaStore.Downloads.RELATIVE_PATH, "Download/OwnPBX")
            }
            val uri = ctx.contentResolver.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, v)
                ?: throw IllegalStateException("no Downloads")
            ctx.contentResolver.openOutputStream(uri)!!.use { out -> file.inputStream().use { it.copyTo(out) } }
            "Downloads/OwnPBX"
        } else {
            val dir = File(ctx.getExternalFilesDir(android.os.Environment.DIRECTORY_DOWNLOADS), "OwnPBX").apply { mkdirs() }
            file.copyTo(File(dir, name), overwrite = true)
            dir.path
        }
    }
