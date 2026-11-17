package br.com.alumbra.oee.maquinas

import android.content.Context
import android.media.AudioManager
import android.media.ToneGenerator
import android.os.Build
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager

/** Apito + vibração dos alertas da tela da máquina (parada sem motivo, máquina sem OP). */
class Alarme(contexto: Context) {

    private val tom = runCatching { ToneGenerator(AudioManager.STREAM_ALARM, 100) }.getOrNull()

    private val vibrador: Vibrator? = if (Build.VERSION.SDK_INT >= 31) {
        (contexto.getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as? VibratorManager)?.defaultVibrator
    } else {
        @Suppress("DEPRECATION")
        contexto.getSystemService(Context.VIBRATOR_SERVICE) as? Vibrator
    }

    /** Toque forte (parada sem motivo). */
    fun urgente() {
        tom?.startTone(ToneGenerator.TONE_CDMA_ALERT_CALL_GUARD, 900)
        vibrador?.vibrate(VibrationEffect.createWaveform(longArrayOf(0, 400, 150, 400), -1))
    }

    /** Toque curto (aviso, ex.: máquina sem OP). */
    fun aviso() {
        tom?.startTone(ToneGenerator.TONE_PROP_BEEP2, 300)
        vibrador?.vibrate(VibrationEffect.createOneShot(250, VibrationEffect.DEFAULT_AMPLITUDE))
    }

    fun liberar() {
        tom?.release()
    }
}
