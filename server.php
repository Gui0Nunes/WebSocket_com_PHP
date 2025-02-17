<?php
// Configuração do WebSocket
$host = "192.168.15.106"; // Endereço do servidor
$port = 8080; // Porta do WebSocket
$null = NULL; // Placeholder para conexões fechadas

// Criando o socket do servidor
$serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($serverSocket, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($serverSocket, $host, $port);
socket_listen($serverSocket);

$clients = [$serverSocket];

echo "Servidor WebSocket rodando em ws://$host:$port\n";

while (true) {
    $changed = $clients;
    socket_select($changed, $null, $null, 0, 10);

    if (in_array($serverSocket, $changed)) {
        $clientSocket = socket_accept($serverSocket);
        $clients[] = $clientSocket;
        $header = socket_read($clientSocket, 1024);
        handshake($clientSocket, $header, $host, $port);
        unset($changed[array_search($serverSocket, $changed)]);
    }

    foreach ($changed as $clientSocket) {
        $data = socket_recv($clientSocket, $buffer, 1024, 0);
        if ($data === false || $data == 0) {
            socket_close($clientSocket);
            unset($clients[array_search($clientSocket, $clients)]);
            continue;
        }

        $message = unmask($buffer);
        echo "Mensagem recebida: $message\n";
        sendMessageToClients($message, $clients, $serverSocket);
    }
}

socket_close($serverSocket);

// Função de handshake WebSocket
function handshake($client, $header, $host, $port)
{
    preg_match("#Sec-WebSocket-Key: (.*)\r\n#", $header, $matches);
    $key = base64_encode(pack('H*', sha1($matches[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
    $response = "HTTP/1.1 101 Switching Protocols\r\n";
    $response .= "Upgrade: websocket\r\n";
    $response .= "Connection: Upgrade\r\n";
    $response .= "Sec-WebSocket-Accept: $key\r\n\r\n";
    socket_write($client, $response, strlen($response));
}

// Decodificar a mensagem do WebSocket
function unmask($text)
{
    $length = ord($text[1]) & 127;
    if ($length == 126) {
        $masks = substr($text, 4, 4);
        $data = substr($text, 8);
    } elseif ($length == 127) {
        $masks = substr($text, 10, 4);
        $data = substr($text, 14);
    } else {
        $masks = substr($text, 2, 4);
        $data = substr($text, 6);
    }
    $text = "";
    for ($i = 0; $i < strlen($data); ++$i) {
        $text .= $data[$i] ^ $masks[$i % 4];
    }
    return $text;
}

// Enviar mensagem para todos os clientes conectados
function sendMessageToClients($message, $clients, $serverSocket)
{
    $message = mask($message);
    foreach ($clients as $client) {
        if ($client != $serverSocket) {
            socket_write($client, $message, strlen($message));
        }
    }
}

// Codificar a mensagem para WebSocket
function mask($text)
{
    $b1 = 0x81;
    $length = strlen($text);
    if ($length <= 125) {
        $header = pack('CC', $b1, $length);
    } elseif ($length <= 65535) {
        $header = pack('CCn', $b1, 126, $length);
    } else {
        $header = pack('CCNN', $b1, 127, $length, 0);
    }
    return $header . $text;
}
?>
