<?php

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("Este arquivo não pode ser acessado diretamente");
}

function woovi_MetaData() {
    return [
        'DisplayName' => 'Woovi Gateway',
        'APIVersion' => '1.0',
    ];
}

function woovi_activate() {
    try {
        // Verifica se a tabela já existe
        if (!Capsule::schema()->hasTable('mod_woovi')) {
            Capsule::schema()->create('mod_woovi', function ($table) {
                $table->increments('id');
                $table->string('payment_id', 255);
                $table->string('type', 50);
                $table->integer('clientid')->nullable();
                $table->integer('invoiceid');
                $table->decimal('amount', 10, 2);
                $table->string('status', 50)->default('Pending');
                $table->string('reason', 255)->nullable();
                $table->text('qr_text')->nullable();
                $table->text('qr_image')->nullable();
                $table->string('transaction_pix_id', 255)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->onUpdate('CURRENT_TIMESTAMP');
            });
        }
        return ['status' => 'success', 'description' => 'Módulo Woovi ativado com sucesso.'];
    } catch (\Exception $e) {
        return ['status' => 'error', 'description' => 'Erro ao ativar o módulo: ' . $e->getMessage()];
    }
}

function woovi_config() {
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Woovi Gateway',
        ],
        'apiurl' => [
            'FriendlyName' => 'API URL',
            'Type' => 'text',
            'Size' => '64',
            'Default' => 'https://api.woovi.com/api/v1/charge',
            'Description' => 'Insira a URL da API Woovi.',
        ],
        'apikey' => [
            'FriendlyName' => 'API Key',
            'Type' => 'text',
            'Size' => '64',
            'Default' => '',
            'Description' => 'Insira sua chave de API da Woovi.',
        ],
    ];
}

function woovi_link($params) {
    $apiKey = $params['apikey'];
    $url = $params['apiurl'];

    // Verifica se já existe uma cobrança para a fatura e cliente
    $existingCharge = Capsule::table('mod_woovi')
        ->where('invoiceid', $params['invoiceid'])
        ->where('clientid', $params['clientdetails']['userid'])
        ->first();

    if ($existingCharge) {
        // Exibe QR Code da cobrança existente
        $QRCodeText = htmlspecialchars($existingCharge->qr_text);
        $QRCodeImg = "data:image/png;base64,{$existingCharge->qr_image}";

        return "
        <div style='text-align: center;'>
            <img src='$QRCodeImg' alt='QR Code' style='width:200px; height:200px; margin-bottom:10px;' />
            <textarea readonly style='width:100%; height:50px;'>$QRCodeText</textarea>
        </div>
        ";
    }

    // Cria uma nova cobrança via API Woovi
    $paymentData = [
        "correlationID" => $params['invoiceid'],
        "value" => (int)($params['amount'] * 100),
        "comment" => "{$params['invoiceid']}",
        "customer" => [
            "name" => $params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname'],
            "email" => $params['clientdetails']['email'],
            "phone" => $params['clientdetails']['phonenumber'] ?? 'N/A',
        ],
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode($paymentData),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json"
        ],
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return "Erro ao conectar com a API Woovi: $err";
    }

    $response = json_decode($response, true);

    if (isset($response['brCode'], $response['qrCodeImage'])) {
        // Armazena a cobrança na tabela mod_woovi
        Capsule::table('mod_woovi')->insert([
            'payment_id' => $response['id'],
            'type' => 'DYNAMIC',
            'clientid' => $params['clientdetails']['userid'],
            'invoiceid' => $params['invoiceid'],
            'amount' => $params['amount'],
            'status' => $response['status'] ?? 'Pending',
            'reason' => $response['comment'] ?? '',
            'qr_text' => $response['brCode'],
            'qr_image' => base64_encode(file_get_contents($response['qrCodeImage'])),
            'transaction_pix_id' => $response['transactionID'] ?? null,
        ]);

        // Exibe o QR Code gerado
        $QRCodeText = htmlspecialchars($response['brCode']);
        $QRCodeImg = "data:image/png;base64," . base64_encode(file_get_contents($response['qrCodeImage']));

        return "
        <div style='text-align: center;'>
            <img src='$QRCodeImg' alt='QR Code' style='width:200px; height:200px; margin-bottom:10px;' />
            <textarea readonly style='width:100%; height:50px;'>$QRCodeText</textarea>
        </div>
        ";
    } else {
        return "Erro ao gerar cobrança: " . ($response['error'] ?? "Falha na API Woovi");
    }
}
?>
