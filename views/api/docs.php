<section class="hero" style="min-height:auto;padding-bottom:2rem">
  <div class="hero-copy">
    <p class="eyebrow">API privada</p>
    <h1 class="hero-brand" style="font-size:clamp(2.2rem,5vw,3.5rem)">Credimax Bank API</h1>
    <p class="hero-lead">API bancaria privada de Credimax: cuentas, transferencias, DEBIN y ECHEQ sobre nuestro ledger. Documentación para integraciones propias.</p>
  </div>
</section>

<section class="section">
  <h2>Autenticación</h2>
  <pre class="code-block">POST /api/v1/login/jwt
{ "username": "email@dominio.com", "password": "****" }

→ { "token": "...", "expires_in": 3600, "token_type": "JWT" }

Authorization: JWT &lt;token&gt;
</pre>

  <h2>Endpoints (bank_id = 900)</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Método</th><th>Ruta</th><th>Descripción</th></tr></thead>
      <tbody>
        <tr><td>GET</td><td>/api/v1/health</td><td>Health check</td></tr>
        <tr><td>GET</td><td>/api/v1/me</td><td>Usuario + cuentas</td></tr>
        <tr><td>GET</td><td>/api/v1/banks/900/accounts/owner</td><td>Listar cuentas / saldos</td></tr>
        <tr><td>GET</td><td>/api/v1/banks/900/accounts/{account_id}/owner/transactions</td><td>Movimientos (headers obp_*)</td></tr>
        <tr><td>GET</td><td>/api/v1/accounts/cbu/{cvu}</td><td>Titularidad CVU/CBU</td></tr>
        <tr><td>GET</td><td>/api/v1/accounts/alias/{alias}</td><td>Titularidad por alias</td></tr>
        <tr><td>POST</td><td>.../TRANSFER/transaction-requests</td><td>Transferencia inmediata (origin_id idempotente)</td></tr>
        <tr><td>POST</td><td>.../DEBIN/transaction-requests</td><td>Crear DEBIN</td></tr>
        <tr><td>POST</td><td>/api/v1/debin/{id}/approve|reject</td><td>Resolver DEBIN</td></tr>
        <tr><td>GET</td><td>.../CHECK</td><td>Listar ECHEQ (obp_status)</td></tr>
        <tr><td>POST</td><td>.../CHECK/transaction-requests</td><td>Emitir ECHEQ</td></tr>
        <tr><td>POST</td><td>/api/v1/echeq/{id}/{action}</td><td>DEPOSIT / REJECT / CANCEL</td></tr>
        <tr><td>PUT</td><td>/api/v1/accounts/alias</td><td>Cambiar alias</td></tr>
      </tbody>
    </table>
  </div>

  <h2>Ejemplo transferencia</h2>
  <pre class="code-block">POST /api/v1/banks/900/accounts/90-0-0001-0-1/owner/transaction-request-types/TRANSFER/transaction-requests
Authorization: JWT ...
{
  "origin_id": "PAY001",
  "to": { "cbu": "9000001..............", "cuit": "20111111112" },
  "value": { "currency": "ARS", "amount": 1500.50 },
  "concept": "VAR",
  "description": "Pago proveedor"
}
</pre>

  <p class="muted">Montos en pesos con decimales. Toda la liquidación ocurre en el ledger interno Credimax.</p>
  <p><a class="btn btn-accent" href="<?= e(url('/register')) ?>">Crear cuenta</a>
     <a class="btn" href="<?= e(url('/banking')) ?>">Ir al banco</a></p>
</section>
