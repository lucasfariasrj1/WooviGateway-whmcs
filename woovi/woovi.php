<?php

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("Este arquivo não pode ser acessado diretamente");
}

function woovi_callback() {
    // Recebe o conteúdo do JSON do callback
    $jsonInput = file_get_contents("php://input");
    $callbackData = json_decode($jsonInput, true);

    if (!$callbackData || !isset($callbackData['event'], $callbackData['charge'])) {
        http_response_code(400);
        echo "Dados inválidos";
        return;
    }

    $event = $callbackData['event'];
    $charge = $callbackData['charge'];

    // Verifica se o evento é de conclusão de pagamento
    if ($event !== 'OPENPIX:CHARGE_COMPLETED') {
        http_response_code(200);
        echo "Evento não processado: $event";
        return;
    }

    $transactionId = $charge['transactionID'] ?? null;
    $comment = $charge['comment'] ?? null;

    if (!$transactionId || !$comment) {
        http_response_code(400);
        echo "Dados incompletos no callback";
        return;
    }

    try {
        // Busca o registro na tabela `mod_woovi` pelo `reason` e verifica o status
        $chargeRecord = Capsule::table('mod_woovi')
            ->where('reason', $comment) // O campo `reason` deve conter o número da fatura
            ->where('transaction_pix_id', $transactionId)
            ->first();

        if (!$chargeRecord) {
            http_response_code(404);
            echo "Cobrança não encontrada para o comentário: $comment e transação: $transactionId";
            return;
        }

        if ($chargeRecord->status === 'COMPLETED') {
            http_response_code(200);
            echo "Cobrança já processada";
            return;
        }

        // Atualiza o status da cobrança para "COMPLETED" na tabela `mod_woovi`
        Capsule::table('mod_woovi')
            ->where('id', $chargeRecord->id)
            ->update([
                'status' => 'COMPLETED',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        // Marca a fatura como paga no WHMCS
        $invoiceId = $chargeRecord->invoiceid;

        addInvoicePayment(
            $invoiceId, // ID da fatura no WHMCS
            $transactionId, // ID da transação
            $charge['value'] / 100, // Valor pago (convertido de centavos para reais)
            0, // Taxa (fee) opcional
            'woovi' // Nome do gateway
        );

        // Aceita o pedido relacionado à fatura, se aplicável
        $orders = Capsule::table('tblorders')
            ->where('invoiceid', $invoiceId)
            ->get();

        foreach ($orders as $order) {
            Capsule::table('tblorders')
                ->where('id', $order->id)
                ->update(['status' => 'Active']);
        }

        http_response_code(200);
        echo "Cobrança processada e fatura #$invoiceId marcada como paga.";
    } catch (\Exception $e) {
        http_response_code(500);
        echo "Erro ao processar o callback: " . $e->getMessage();
    }
}
