package br.com.alumbra.oee

import android.Manifest
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import br.com.alumbra.oee.rede.EstadoRede
import br.com.alumbra.oee.rede.VerificadorRede
import br.com.alumbra.oee.ui.TelaInicial
import br.com.alumbra.oee.ui.TemaOee

class MainActivity : ComponentActivity() {

    private var tentativa by mutableIntStateOf(0)

    private val pedirLocalizacao =
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { tentativa++ }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            TemaOee {
                var rede by remember { mutableStateOf<EstadoRede?>(null) }
                LaunchedEffect(tentativa) {
                    val estado = VerificadorRede.estadoAtual(this@MainActivity)
                    rede = estado
                    rede = estado.copy(portalAcessivel = VerificadorRede.portalResponde())
                }
                Box(Modifier.fillMaxSize().safeDrawingPadding()) {
                    TelaInicial(
                        rede = rede,
                        aoVerificarRede = { rede = null; tentativa++ },
                        aoPedirPermissao = {
                            pedirLocalizacao.launch(
                                arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION),
                            )
                        },
                    )
                }
            }
        }
    }

    override fun onResume() {
        super.onResume()
        tentativa++
    }
}
