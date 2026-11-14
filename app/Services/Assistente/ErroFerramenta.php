<?php

namespace App\Services\Assistente;

use RuntimeException;

/** Erro de uso de uma consulta do assistente (a mensagem volta para a Claude, que pode corrigir o pedido). */
class ErroFerramenta extends RuntimeException {}
