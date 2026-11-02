// SPDX-License-Identifier: GPL-2.0-or-later
package org.ownpbx.myphone

import android.content.Context
import android.os.Build
import java.io.File
import java.io.PrintWriter
import java.io.StringWriter
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import org.ownpbx.BuildConfig

/** Saves the last crash to a file so the next launch can show it (no adb
 *  needed to report a bug). Only the stack trace, app version and phone
 *  model are written; it stays on the phone until the user copies it. */
object CrashLog {
    private var installed = false
    private fun file(ctx: Context) = File(ctx.filesDir, "last_crash.txt")

    fun install(ctx: Context) {
        if (installed) return
        installed = true
        val app = ctx.applicationContext
        val previous = Thread.getDefaultUncaughtExceptionHandler()
        Thread.setDefaultUncaughtExceptionHandler { t, e ->
            try {
                val sw = StringWriter()
                e.printStackTrace(PrintWriter(sw))
                file(app).writeText(
                    "My Phone ${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})\n" +
                    "Android ${Build.VERSION.RELEASE} (API ${Build.VERSION.SDK_INT}), ${Build.MANUFACTURER} ${Build.MODEL}\n" +
                    "${SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).format(Date())}, thread ${t.name}\n\n" +
                    sw.toString().take(20_000))
            } catch (_: Throwable) {}
            previous?.uncaughtException(t, e)
        }
    }

    fun read(ctx: Context): String? = file(ctx).takeIf { it.exists() }?.readText()

    /**
     * Why Android ended My Phone the last few times (Android 11+ keeps this record): a crash, the
     * phone's battery saver, low memory, a force stop... Newest first, as short plain lines.
     */
    fun lastExits(ctx: Context, max: Int = 5): List<String> {
        if (Build.VERSION.SDK_INT < 30) return emptyList()
        return try {
            val am = ctx.getSystemService(android.app.ActivityManager::class.java)
            val fmt = SimpleDateFormat("MMM d, h:mm a", Locale.US)
            am.getHistoricalProcessExitReasons(ctx.packageName, 0, max).map { e ->
                fmt.format(Date(e.timestamp)) + ": " + reason(e.reason) +
                    (e.description?.takeIf { it.isNotBlank() }?.let { " ($it)" } ?: "")
            }
        } catch (_: Exception) {
            emptyList()
        }
    }

    private fun reason(r: Int): String =
        when (r) {
            1 -> "closed itself (normal)"
            2 -> "killed by the system, often the phone maker's battery saver"
            3 -> "closed by Android to free memory"
            4 -> "crashed (app error)"
            5 -> "crashed in the phone engine"
            6 -> "froze and Android closed it"
            7 -> "failed to start"
            8 -> "restarted after a permission change"
            9 -> "closed by Android for using too much battery"
            10 -> "force-stopped (by you, or by the phone's battery manager)"
            11 -> "stopped from Android's task manager"
            12 -> "closed because something it relies on stopped"
            14 -> "frozen by Android while unused"
            15, 16 -> "restarted for an app update"
            else -> "stopped (other reason)"
        }
    fun clear(ctx: Context) { file(ctx).delete() }
}
