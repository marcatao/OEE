package br.com.alumbra.oee.alertas

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import br.com.alumbra.oee.sessao.Sessao

/** Celular religado (ou app atualizado): volta a vigiar os alertas se houver alguém logado. */
class IniciarNoBoot : BroadcastReceiver() {
    override fun onReceive(contexto: Context, intent: Intent) {
        if (intent.action in listOf(Intent.ACTION_BOOT_COMPLETED, Intent.ACTION_MY_PACKAGE_REPLACED) && Sessao(contexto).token != null) {
            runCatching { ServicoAlertas.iniciar(contexto) }
        }
    }
}
