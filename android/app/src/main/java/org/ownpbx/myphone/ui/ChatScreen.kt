// SPDX-License-Identifier: GPL-2.0-or-later
@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class, androidx.compose.foundation.ExperimentalFoundationApi::class)

package org.ownpbx.myphone.ui

import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import org.ownpbx.myphone.data.Attachment
import org.ownpbx.myphone.data.Msg
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.rememberScrollState
import androidx.compose.ui.platform.LocalContext

@Composable
fun ChatScreen(model: AppModel, peer: String, snack: SnackbarHostState, back: () -> Unit) {
    var msgs by remember { mutableStateOf<List<Msg>>(emptyList()) }   // newest first
    var text by rememberSaveable { mutableStateOf("") }
    var sending by remember { mutableStateOf(false) }
    var selected by remember { mutableStateOf(setOf<Long>()) }
    var menu by remember { mutableStateOf(false) }
    // "From": the user's numbers for texts to phone numbers (shown when there's a choice).
    var fromNumbers by remember { mutableStateOf<List<String>>(emptyList()) }
    var fromDid by remember { mutableStateOf("") }
    var fromPicked by remember { mutableStateOf(false) }   // the user chose; stop following the PBX
    var fromMenu by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val list = rememberLazyListState()
    val clip = LocalClipboardManager.current
    val haptic = LocalHapticFeedback.current
    val ctx = LocalContext.current
    // Photos/videos waiting to go with the next message, the emoji panel,
    // the full-screen viewer, and the message being forwarded.
    var attachments by remember { mutableStateOf<List<Attachment>>(emptyList()) }
    var emojiOpen by remember { mutableStateOf(false) }
    var viewer by remember { mutableStateOf<Pair<java.io.File, org.ownpbx.myphone.data.MediaItem>?>(null) }
    var forwarding by remember { mutableStateOf<Msg?>(null) }
    val picker = rememberLauncherForActivityResult(ActivityResultContracts.PickMultipleVisualMedia(MAX_ATTACH)) { uris ->
        if (uris.isEmpty()) return@rememberLauncherForActivityResult
        model.bg.launch {
            val got = mutableListOf<Attachment>()
            for (u in uris) {
                val (a, err) = kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) {
                    try { readAttachment(ctx, u) } catch (e: Exception) { null to "Couldn't open that file." }
                }
                if (a != null) got += a else model.notice = err
            }
            val all = attachments + got
            if (all.size > MAX_ATTACH) model.notice = "Up to $MAX_ATTACH photos or videos at a time."
            attachments = all.take(MAX_ATTACH)
        }
    }
    fun saveMedia(m: Msg) = model.bg.launch {
        for (it in m.media) {
            val f = model.guard { a -> a.mediaFile(it.url, ctx.cacheDir) } ?: return@launch
            try { model.notice = "Saved to " + saveToDownloads(ctx, f, it.mime) }
            catch (e: Exception) { model.notice = "Couldn't save: ${e.message}" }
        }
    }

    suspend fun refresh() {
        model.guard { it.thread(peer) }?.let {
            msgs = it.messages
            fromNumbers = it.from_numbers
            if (!fromPicked || fromDid !in it.from_numbers) fromDid = it.from_did
            it.messages.firstOrNull()?.let { m -> model.store.markSeen(peer, m.id) }
        }
    }
    LaunchedEffect(peer) { while (true) { if (org.ownpbx.myphone.Notifier.appVisible) refresh(); delay(5_000) } }

    fun send() {
        val body = text.trim()
        if ((body.isEmpty() && attachments.isEmpty()) || sending) return
        sending = true
        scope.launch {
            val from = if (fromNumbers.size > 1) fromDid else ""
            if (model.change { it.send(peer, body, from, attachments) }) {
                text = ""; attachments = emptyList(); emojiOpen = false; refresh(); list.animateScrollToItem(0)
            }
            sending = false
        }
    }

    Scaffold(
        snackbarHost = { SnackbarHost(snack) },
        topBar = {
            if (selected.isNotEmpty()) TopAppBar(
                title = { Text("${selected.size} selected") },
                navigationIcon = { IconButton({ selected = emptySet() }) { Icon(Icons.Filled.Close, "Cancel") } },
                actions = {
                    val one = if (selected.size == 1) msgs.firstOrNull { it.id in selected } else null
                    if (one != null) {
                        IconButton({ forwarding = one; selected = emptySet() }) {
                            Icon(Icons.AutoMirrored.Filled.Forward, "Forward")
                        }
                        if (one.media.isNotEmpty()) IconButton({ saveMedia(one); selected = emptySet() }) {
                            Icon(Icons.Filled.Download, "Save to Downloads")
                        }
                        if (!one.mine) IconButton({
                            selected = emptySet()
                            scope.launch { if (model.change { it.report("message", one.id) }) model.notice = "Reported. The administrator has been notified." }
                        }) { Icon(Icons.Filled.Flag, "Report") }
                    }
                    IconButton({
                        clip.setText(AnnotatedString(msgs.filter { it.id in selected }.reversed().joinToString("\n") { it.text ?: it.body }))
                        selected = emptySet()
                    }) { Icon(Icons.Filled.ContentCopy, "Copy") }
                    IconButton({
                        val ids = selected.toList(); selected = emptySet()
                        model.bg.launch { model.change { it.deleteMessages(ids = ids) }; refresh() }
                    }) { Icon(Icons.Filled.Delete, "Delete") }
                },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = MaterialTheme.colorScheme.secondaryContainer),
            ) else TopAppBar(
                navigationIcon = { IconButton(back) { Icon(Icons.AutoMirrored.Filled.ArrowBack, "Back") } },
                title = {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Avatar(peer); Spacer(Modifier.width(12.dp)); Text(prettyNumber(peer))
                    }
                },
                actions = {
                    if (peer.any { it.isDigit() }) IconButton({ model.callRequest = peer }) { Icon(Icons.Filled.Call, "Call") }
                    IconButton({ menu = true }) { Icon(Icons.Filled.MoreVert, "More") }
                    DropdownMenu(menu, { menu = false }) {
                        DropdownMenuItem({ Text("Block") }, leadingIcon = { Icon(Icons.Filled.Block, null) }, onClick = {
                            menu = false; scope.launch { model.change { it.block(peer) }.also { if (it) model.notice = "Blocked" } }
                        })
                        DropdownMenuItem({ Text("Delete conversation") }, leadingIcon = { Icon(Icons.Filled.Delete, null) }, onClick = {
                            menu = false; model.bg.launch { if (model.change { it.deleteMessages(peers = listOf(peer)) }) back() }
                        })
                    }
                },
            )
        },
        bottomBar = {
          Column(Modifier.fillMaxWidth().navigationBarsPadding().imePadding()) {
            if (fromNumbers.size > 1) {
                Box(Modifier.padding(start = 12.dp, top = 4.dp)) {
                    AssistChip(
                        onClick = { fromMenu = true },
                        label = { Text("From ${prettyNumber(fromDid)}") },
                        leadingIcon = { Icon(Icons.Filled.SimCard, null, Modifier.size(18.dp)) },
                        trailingIcon = { Icon(Icons.Filled.ArrowDropDown, null) },
                    )
                    DropdownMenu(fromMenu, { fromMenu = false }) {
                        fromNumbers.forEach { d ->
                            DropdownMenuItem(
                                { Text(prettyNumber(d)) },
                                onClick = { fromDid = d; fromPicked = true; fromMenu = false },
                                trailingIcon = { if (d == fromDid) Icon(Icons.Filled.Check, null) },
                            )
                        }
                    }
                }
            }
            if (attachments.isNotEmpty()) Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState())
                .padding(start = 12.dp, top = 6.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                attachments.forEach { a ->
                    InputChip(
                        selected = false,
                        onClick = { attachments = attachments - a },
                        label = { Text(a.name.take(18) + " · " + (a.bytes.size / 1024).let { if (it > 1024) "${it / 1024} MB" else "$it KB" }) },
                        leadingIcon = { Icon(if (a.mime.startsWith("video")) Icons.Filled.Videocam else Icons.Filled.Image, null, Modifier.size(18.dp)) },
                        trailingIcon = { Icon(Icons.Filled.Close, "Remove", Modifier.size(18.dp)) },
                    )
                }
            }
            Row(Modifier.fillMaxWidth().padding(8.dp),
                verticalAlignment = Alignment.CenterVertically) {
                IconButton({ emojiOpen = !emojiOpen }) {
                    Icon(if (emojiOpen) Icons.Filled.Keyboard else Icons.Filled.EmojiEmotions, "Emoji")
                }
                IconButton({
                    picker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageAndVideo))
                }, enabled = attachments.size < MAX_ATTACH) { Icon(Icons.Filled.AttachFile, "Photo or video") }
                OutlinedTextField(text, { text = it.take(1000) }, Modifier.weight(1f), maxLines = 5,
                    placeholder = { Text("Text message") }, shape = RoundedCornerShape(24.dp))
                Spacer(Modifier.width(8.dp))
                FilledIconButton(::send, Modifier.size(52.dp),
                    enabled = (text.isNotBlank() || attachments.isNotEmpty()) && !sending) {
                    if (sending) CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp)
                    else Icon(Icons.AutoMirrored.Filled.Send, "Send")
                }
            }
            if (emojiOpen) EmojiPanel { e -> if (text.length + e.length <= 1000) text += e }
          }
        },
    ) { pad ->
        LazyColumn(Modifier.padding(pad).fillMaxSize(), state = list, reverseLayout = true,
            contentPadding = PaddingValues(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            items(msgs, key = { it.id }) { m ->
                val isSel = m.id in selected
                Row(Modifier.fillMaxWidth(), horizontalArrangement = if (m.mine) Arrangement.End else Arrangement.Start) {
                    Surface(
                        shape = RoundedCornerShape(20.dp, 20.dp, if (m.mine) 4.dp else 20.dp, if (m.mine) 20.dp else 4.dp),
                        color = when {
                            isSel -> MaterialTheme.colorScheme.tertiaryContainer
                            m.mine -> MaterialTheme.colorScheme.primary
                            else -> MaterialTheme.colorScheme.surfaceVariant
                        },
                        modifier = Modifier.widthIn(max = 300.dp).combinedClickable(
                            onClick = { if (selected.isNotEmpty()) selected = if (isSel) selected - m.id else selected + m.id },
                            onLongClick = { haptic.performHapticFeedback(HapticFeedbackType.LongPress); selected = selected + m.id }),
                    ) {
                        Column(Modifier.padding(horizontal = 14.dp, vertical = 8.dp)) {
                            val t = m.text ?: m.body
                            if (t.isNotBlank() || m.media.isEmpty()) Text(t)
                            m.media.forEach { item ->
                                MediaBubble(model, item, onLong = {
                                    haptic.performHapticFeedback(HapticFeedbackType.LongPress); selected = selected + m.id
                                }) { f ->
                                    if (selected.isEmpty()) viewer = f to item
                                    else selected = if (isSel) selected - m.id else selected + m.id
                                }
                            }
                            Text(shortTime(m.sent_at) + if (m.via_did.isNotEmpty()) " · via ${prettyNumber(m.via_did)}" else "",
                                style = MaterialTheme.typography.labelSmall)
                        }
                    }
                }
            }
        }
    }
    viewer?.let { (f, item) ->
        MediaViewer(f, item.kind, onSave = {
            model.bg.launch {
                try { model.notice = "Saved to " + saveToDownloads(ctx, f, item.mime) }
                catch (e: Exception) { model.notice = "Couldn't save: ${e.message}" }
            }
        }, onClose = { viewer = null })
    }
    forwarding?.let { m ->
        var to by remember { mutableStateOf("") }
        var busy by remember { mutableStateOf(false) }
        AlertDialog(
            onDismissRequest = { if (!busy) forwarding = null },
            title = { Text("Forward") },
            text = {
                Column {
                    Text((m.text ?: m.body).take(120), style = MaterialTheme.typography.bodySmall)
                    Spacer(Modifier.height(8.dp))
                    OutlinedTextField(to, { to = it.take(20) }, label = { Text("Extension or phone number") }, singleLine = true)
                }
            },
            confirmButton = {
                TextButton({
                    busy = true
                    scope.launch {
                        val ok = model.change { it.send(to.trim(), m.text ?: m.body, fwd = m.media.map { x -> x.ref }) }
                        busy = false
                        if (ok) { forwarding = null; model.notice = "Forwarded" }
                    }
                }, enabled = to.isNotBlank() && !busy) { Text("Send") }
            },
            dismissButton = { TextButton({ forwarding = null }, enabled = !busy) { Text("Cancel") } },
        )
    }
}
