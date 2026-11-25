# BNPL-1664: selección de pruebas para QA (solo local, contra mock)

Todo corre contra el **mock local**: Magento local (docker-magento), la API de Aplazo reemplazada por
`stub-module/` (LocalDev_AplazoStub) y la ventana de Aplazo servida por `mock_https.py`. No se llama a Aplazo, no se
usa STG y no hay pagos reales. Un resultado aquí **no** demuestra los contratos externos ni la integración real.

## Qué cubre

| Clave | Escenario de `pf_e2e.py` | Cubre |
|---|---|---|
| `start_no_order` | `double_start` | Inicio: un solo intento y un solo createLoan, sin orden ni reserva |
| `close_unpaid` | `close_unpaid` | Cierre sin pago: sin orden ni reserva, el carrito se conserva; al reabrir se reutiliza el intento (sin otro createLoan) |
| `uncertain_start` | `us_uncertain_reclick` | createLoan falla (incierto); el mismo clic otra vez responde `uncertain` y no hace otro createLoan |
| `paid_once` | `paid_message` | Pago confirmado: una orden con el número del intento, una factura, reserva nativa −1; repetir complete, webhook y reconcile no duplica nada |
| `concurrent_finalization` | `concurrent_webhook_first` | Webhook y complete al mismo tiempo: una orden, una factura, una reserva |
| `redirect_unchanged` | `redirect_regression` | Modo redirect: orden antes de Aplazo, sin intento ni popup ni `mode=popup`; reservas como antes |

`paid_once` y `concurrent_finalization` cubren la finalización repetida.

## Requisitos

- Stack docker-magento (Magento 2.4.8 + MSI + RabbitMQ) en marcha, con esta rama montada en
  `app/code/Aplazo/AplazoPayment` y `stub-module/` instalado como `app/code/LocalDev/AplazoStub` (habilitado).
- `https://magento.test` resolviendo a 127.0.0.1.
- `mock_https.py` escuchando en 127.0.0.1:8443 (su certificado autofirmado está en `../.out/magento-e2e`).
- Google Chrome (`CHROME` para otra ruta). El runner abre un Chrome de prueba propio con `--host-resolver-rules`.
- Python 3 del sistema. No hay dependencias extra.

## Comando

```sh
cd dev/popup-harness/magento-e2e
MAGENTO_DIR=~/Developer/Aplazo/magento248 python3 -I qa_selection.py            # toda la selección
MAGENTO_DIR=~/Developer/Aplazo/magento248 python3 -I qa_selection.py paid_once  # una o varias claves
```

Por defecto ejecuta antes `pf_e2e.setup()`, igual que `pf_e2e.py setup`: productos sintéticos `APLAZO-1664-*`, modo
popup en el website y limpieza de los switches del stub. Con `--no-setup` lo omite. Cada escenario tarda entre 20 y
60 s.

## Cómo leer el resultado

Al final imprime una línea por escenario y escribe `../.out/magento-e2e/qa-selection-<fecha>.json`. La evidencia
completa de cada escenario queda en `../.out/magento-e2e/pf.jsonl`, igual que con `pf_e2e.py`.

- `PASS`: se cumplió la aserción del escenario. En `uncertain_start`, que en `pf_e2e.py` solo registra pasos
  (`ok=None`), se aplican los criterios explícitos de `qa_selection.py`:
  - las dos respuestas son `uncertain`;
  - hay un solo createLoan;
  - queda un solo intento, en estado `uncertain`;
  - no hay orden ni reserva.
- `FAIL`: el escenario terminó, pero no se cumplió su aserción. Es un posible defecto: revisar la entrada en
  `pf.jsonl` antes de concluir.
- `ERROR`: el escenario se interrumpió (excepción o timeout). Suele ser el entorno (Chrome, Docker, mock); revisar
  `reason` y `where`.
- `NOT_RUN`: falló un requisito y no se ejecutó nada. El motivo aparece como `environment: ...`.

El código de salida es 0 solo si todo es `PASS`. El modo `pf_e2e.py` sigue igual: `python3 -I pf_e2e.py
<test> ...` registra `ok=False` para los escenarios observacionales (`ok=None`), así que su `SUMMARY` los muestra
como FAIL.
