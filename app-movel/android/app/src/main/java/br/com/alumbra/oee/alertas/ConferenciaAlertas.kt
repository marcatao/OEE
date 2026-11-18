package br.com.alumbra.oee.alertas

import android.Manifest
import android.app.NotificationManager
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.media.AudioManager
import android.net.Uri
import android.os.Build
import android.os.PowerManager
import android.provider.Settings

/** Um item que o celular precisa ter liberado para tocar com a tela apagada e o app fechado. */
data class ItemAlerta(val titulo: String, val ok: Boolean, val ajuste: Intent?)

/** Confere o que o Android precisa para os alertas funcionarem com a tela apagada. */
object ConferenciaAlertas {

    fun itens(c: Context): List<ItemAlerta> {
        val pacote = Uri.parse("package:${c.packageName}")
        val nm = c.getSystemService(NotificationManager::class.java)
        val notificacoes = nm.areNotificationsEnabled() &&
            (Build.VERSION.SDK_INT < 33 || c.checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED)
        val bateria = c.getSystemService(PowerManager::class.java).isIgnoringBatteryOptimizations(c.packageName)
        val telaCheia = Build.VERSION.SDK_INT < 34 || nm.canUseFullScreenIntent()
        val audio = c.getSystemService(AudioManager::class.java)
        val volume = audio.getStreamVolume(AudioManager.STREAM_ALARM) > 0
        return listOf(
            ItemAlerta("Notificações permitidas", notificacoes,
                Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, c.packageName)),
            ItemAlerta("Fora da economia de bateria", bateria, Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, pacote)),
            ItemAlerta("Abrir em tela cheia sobre o bloqueio", telaCheia,
                if (Build.VERSION.SDK_INT >= 34) Intent(Settings.ACTION_MANAGE_APP_USE_FULL_SCREEN_INTENT, pacote) else null),
            ItemAlerta("Volume de alarme ligado", volume, Intent(Settings.ACTION_SOUND_SETTINGS)),
        )
    }

    fun tudoOk(c: Context): Boolean = itens(c).all { it.ok }
}
