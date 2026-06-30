package com.einundzwanzig.calendar

import android.content.Context
import android.content.Intent
import android.provider.CalendarContract
import android.util.Log
import com.nativephp.mobile.bridge.BridgeFunction

/**
 * Functions related to the system calendar.
 * Namespace: "Calendar.*"
 */
object CalendarFunctions {

    /**
     * Open the system calendar's "new event" editor (ACTION_INSERT), prefilled.
     * Requires no permission — the user reviews and saves the event themselves.
     *
     * Parameters:
     *   - title: (optional) string
     *   - location: (optional) string
     *   - description: (optional) string
     *   - beginTime: (optional) number - start as milliseconds since epoch (UTC)
     *   - endTime: (optional) number - end as milliseconds since epoch (UTC)
     */
    class Insert(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val title = parameters["title"] as? String ?: ""
            val location = parameters["location"] as? String ?: ""
            val description = parameters["description"] as? String ?: ""
            val beginTime = (parameters["beginTime"] as? Number)?.toLong() ?: 0L
            val endTime = (parameters["endTime"] as? Number)?.toLong() ?: 0L

            Log.d("CalendarFunctions.Insert", "Insert event requested - title: $title, begin: $beginTime")

            return try {
                val intent = Intent(Intent.ACTION_INSERT).apply {
                    data = CalendarContract.Events.CONTENT_URI

                    if (title.isNotEmpty()) {
                        putExtra(CalendarContract.Events.TITLE, title)
                    }
                    if (location.isNotEmpty()) {
                        putExtra(CalendarContract.Events.EVENT_LOCATION, location)
                    }
                    if (description.isNotEmpty()) {
                        putExtra(CalendarContract.Events.DESCRIPTION, description)
                    }
                    if (beginTime > 0L) {
                        putExtra(CalendarContract.EXTRA_EVENT_BEGIN_TIME, beginTime)
                    }
                    if (endTime > 0L) {
                        putExtra(CalendarContract.EXTRA_EVENT_END_TIME, endTime)
                    }

                    addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                }

                context.startActivity(intent)
                Log.d("CalendarFunctions.Insert", "Calendar editor opened")

                mapOf("added" to true)
            } catch (e: Exception) {
                Log.e("CalendarFunctions.Insert", "Error opening calendar editor: ${e.message}", e)
                mapOf("error" to (e.message ?: "Unknown error"))
            }
        }
    }
}
