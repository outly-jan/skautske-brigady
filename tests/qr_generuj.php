<?php
// Vygeneruje zkušební QR kódy (PNG + očekávaný text) do zadané složky.
// Ověření nezávislou čtečkou: python3 tests/qr_over.py <složka>  (vyžaduje pip install zxing-cpp pillow)
require __DIR__ . '/wp_stubs.php';
require __DIR__ . '/../skautske-brigady.php';
$out = $argv[1];
$texty = [];
foreach ([1, 5, 14, 15, 26, 27, 42, 43, 62, 63, 84, 85, 106, 107, 122, 123, 152, 153, 180, 181, 213] as $n) {
    $texty[] = substr(str_repeat('Skaut Chlumec 0123456789 ', 20), 0, $n);
}
$texty[] = 'SPD*1.0*ACC:' . sb_cz_iban('2300221146/2010') . '*AM:2400.00*CC:CZK*MSG:Poplatek 2027 Applova Julie, Appl Josef + dar 500';
$texty[] = 'Žluťoučký kůň úpěl ďábelské ódy';
foreach ($texty as $i => $txt) {
    file_put_contents("$out/qr$i.png", sb_qr_png($txt, 4));
    file_put_contents("$out/qr$i.txt", $txt);
}
echo count($texty) . " QR kódů vygenerováno\n";
