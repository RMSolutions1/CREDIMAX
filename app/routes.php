<?php
declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\BankingController;
use App\Controllers\DashboardController;
use App\Controllers\FundsController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\LoanController;
use App\Controllers\NotificationController;
use App\Controllers\OnboardingController;
use App\Controllers\OtpController;
use App\Controllers\ProfileController;
use App\Controllers\SiteController;
use App\Controllers\WalletController;
use App\Controllers\MercadoPagoController;
use App\Controllers\Admin\MercadoPagoAdminController;
use App\Controllers\Api\BankApiController;
use App\Controllers\Api\MpWebhookController;
use App\Core\Router;

$router = new Router();

$router->get('/', [HomeController::class, 'index']);
$router->get('/health', [HealthController::class, 'index']);
$router->get('/healthz', [HealthController::class, 'index']);

// Marketing / sitio público
$router->get('/como-funciona', [SiteController::class, 'howItWorks']);
$router->get('/productos', [SiteController::class, 'products']);
$router->get('/pedir-credito', [SiteController::class, 'borrowLanding']);
$router->get('/invertir', [SiteController::class, 'investLanding']);
$router->get('/por-que-credimax', [SiteController::class, 'why']);
$router->get('/pyme', [SiteController::class, 'pymeLanding']);
$router->get('/requisitos', [SiteController::class, 'requirements']);
$router->get('/tasas', [SiteController::class, 'rates']);
$router->get('/costos', [SiteController::class, 'costs']);
$router->get('/estadisticas', [SiteController::class, 'stats']);
$router->get('/seguridad', [SiteController::class, 'security']);
$router->get('/nosotros', [SiteController::class, 'about']);
$router->get('/faq', [SiteController::class, 'faq']);
$router->get('/ayuda', [SiteController::class, 'help']);
$router->get('/contacto', [SiteController::class, 'contact']);
$router->post('/contacto', [SiteController::class, 'contactSend']);
$router->get('/simulador', [SiteController::class, 'simulator']);
$router->get('/api/simulator', [SiteController::class, 'simulatorCalc']);
$router->get('/simulador-inversion', [SiteController::class, 'investSimulator']);
$router->get('/api/simulator-inversion', [SiteController::class, 'investSimulatorCalc']);
$router->get('/mapa-del-sitio', [SiteController::class, 'sitemap']);
$router->get('/sitemap.xml', [SiteController::class, 'sitemapXml']);
$router->get('/robots.txt', [SiteController::class, 'robotsTxt']);

// Legales
$router->get('/legales/terminos', [SiteController::class, 'terms']);
$router->get('/legales/privacidad', [SiteController::class, 'privacy']);
$router->get('/legales/cookies', [SiteController::class, 'cookies']);
$router->get('/legales/contrato-credito', [SiteController::class, 'loanContract']);
$router->get('/legales/manual-operativo', [SiteController::class, 'operatingManual']);
$router->get('/legales/pep', [SiteController::class, 'pep']);
$router->get('/legales/defensa-consumidor', [SiteController::class, 'consumer']);
$router->get('/legales/cumplimiento', [SiteController::class, 'compliance']);
$router->get('/legales/fideicomiso', [SiteController::class, 'fideicomiso']);
$router->get('/legales/adhesion', [SiteController::class, 'adhesion']);
$router->get('/legales/arrepentimiento', [SiteController::class, 'regretForm']);
$router->post('/legales/arrepentimiento', [SiteController::class, 'regretSubmit']);
$router->get('/legales/usuario-financiero', [SiteController::class, 'usuarioFinanciero']);
$router->get('/legales/baja', [ProfileController::class, 'closeForm']);
$router->post('/legales/baja', [ProfileController::class, 'closeSubmit']);

$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/register', [AuthController::class, 'showRegister']);
$router->post('/register', [AuthController::class, 'register']);
$router->get('/forgot-password', [AuthController::class, 'showForgot']);
$router->post('/forgot-password', [AuthController::class, 'forgot']);
$router->get('/reset-password', [AuthController::class, 'showReset']);
$router->post('/reset-password', [AuthController::class, 'reset']);
$router->post('/logout', [AuthController::class, 'logout']);

// OTP / 2FA (email / sms / totp)
$router->get('/otp/verify', [OtpController::class, 'showVerify']);
$router->post('/otp/verify', [OtpController::class, 'submitVerify']);
$router->post('/otp/resend', [OtpController::class, 'resend']);
$router->get('/otp/totp/setup', [OtpController::class, 'totpSetup']);
$router->post('/otp/totp/confirm', [OtpController::class, 'totpConfirm']);
$router->post('/otp/totp/disable', [OtpController::class, 'totpDisable']);

// Onboarding / KYC
$router->get('/onboarding', [OnboardingController::class, 'start']);
$router->get('/onboarding/personal', [OnboardingController::class, 'personal']);
$router->post('/onboarding/personal', [OnboardingController::class, 'savePersonal']);
$router->get('/onboarding/contacto', [OnboardingController::class, 'contact']);
$router->post('/onboarding/otp/send', [OnboardingController::class, 'sendOtp']);
$router->post('/onboarding/otp/verify', [OnboardingController::class, 'verifyOtp']);
$router->get('/onboarding/laboral', [OnboardingController::class, 'employment']);
$router->post('/onboarding/laboral', [OnboardingController::class, 'saveEmployment']);
$router->get('/onboarding/pep', [OnboardingController::class, 'pepForm']);
$router->post('/onboarding/pep', [OnboardingController::class, 'savePep']);
$router->get('/onboarding/kyc', [OnboardingController::class, 'kycWizard']);
$router->post('/onboarding/kyc', [OnboardingController::class, 'kycSubmit']);

$router->get('/dashboard', [DashboardController::class, 'index']);

$router->get('/wallet', [WalletController::class, 'index']);
$router->post('/wallet/deposit', [WalletController::class, 'deposit']);
$router->post('/wallet/withdraw', [WalletController::class, 'withdraw']);
$router->post('/wallet/transfer', [WalletController::class, 'transfer']);
$router->get('/wallet/qr', [WalletController::class, 'qr']);
$router->post('/wallet/qr/pay', [WalletController::class, 'payQr']);

// Sub-cuenta Mercado Pago (cash-in real, cobros y vinculación de cuenta)
$router->get('/wallet/mp', [MercadoPagoController::class, 'index']);
$router->post('/wallet/mp/cargar', [MercadoPagoController::class, 'topup']);
$router->get('/wallet/mp/retorno', [MercadoPagoController::class, 'callback']);
$router->post('/wallet/mp/cobro', [MercadoPagoController::class, 'createCharge']);
$router->get('/wallet/mp/cobro/{id}', [MercadoPagoController::class, 'showCharge']);
$router->post('/wallet/mp/cobro/{id}/cancelar', [MercadoPagoController::class, 'cancelCharge']);
$router->get('/wallet/mp/vincular', [MercadoPagoController::class, 'startLink']);
$router->get('/wallet/mp/vincular/callback', [MercadoPagoController::class, 'finishLink']);
$router->post('/wallet/mp/desvincular', [MercadoPagoController::class, 'unlink']);
$router->get('/cobro/{ref}', [MercadoPagoController::class, 'publicCharge']);

// Webhook de Mercado Pago (sin sesión ni CSRF: se valida por firma HMAC)
$router->post('/webhooks/mercadopago', [MpWebhookController::class, 'handle']);
$router->get('/webhooks/mercadopago', [MpWebhookController::class, 'handle']);

$router->get('/funds', [FundsController::class, 'index']);
$router->post('/funds/deposit', [FundsController::class, 'requestDeposit']);
$router->post('/funds/mandate', [FundsController::class, 'saveMandate']);

$router->get('/banking', [BankingController::class, 'index']);
$router->get('/banking/transfer', [BankingController::class, 'transferForm']);
$router->post('/banking/transfer', [BankingController::class, 'transfer']);
$router->post('/banking/lookup', [BankingController::class, 'lookup']);
$router->get('/banking/debin', [BankingController::class, 'debin']);
$router->post('/banking/debin', [BankingController::class, 'debinCreate']);
$router->post('/banking/debin/{id}', [BankingController::class, 'debinDecide']);
$router->get('/banking/echeq', [BankingController::class, 'echeq']);
$router->post('/banking/echeq', [BankingController::class, 'echeqIssue']);
$router->post('/banking/echeq/{id}', [BankingController::class, 'echeqAction']);
$router->get('/banking/alias', [BankingController::class, 'aliasForm']);
$router->post('/banking/alias', [BankingController::class, 'aliasSave']);
$router->get('/banking/api-keys', [BankingController::class, 'apiKeys']);
$router->post('/banking/api-keys', [BankingController::class, 'apiKeyCreate']);

$router->get('/marketplace', [LoanController::class, 'marketplace']);
$router->get('/loans', [LoanController::class, 'myLoans']);
$router->get('/loans/create', [LoanController::class, 'createForm']);
$router->post('/loans/create', [LoanController::class, 'create']);
$router->get('/loans/{id}', [LoanController::class, 'show']);
$router->post('/loans/{id}/fund', [LoanController::class, 'fund']);
$router->post('/loans/{id}/pay', [LoanController::class, 'pay']);
$router->get('/investments', [LoanController::class, 'investments']);

$router->get('/profile', [ProfileController::class, 'index']);
$router->post('/profile', [ProfileController::class, 'update']);
$router->get('/kyc', [ProfileController::class, 'kycForm']);
$router->post('/kyc', [ProfileController::class, 'kycSubmit']);

$router->get('/notifications', [NotificationController::class, 'index']);

$router->get('/admin', [AdminController::class, 'index']);
$router->get('/admin/users', [AdminController::class, 'users']);
$router->post('/admin/users/{id}/toggle', [AdminController::class, 'toggleUser']);
$router->get('/admin/kyc', [AdminController::class, 'kyc']);
$router->get('/admin/kyc/{userId}/doc/{docId}', [AdminController::class, 'kycDocument']);
$router->post('/admin/kyc/{id}', [AdminController::class, 'kycReview']);
$router->get('/admin/loans', [AdminController::class, 'loans']);
$router->get('/admin/products', [AdminController::class, 'products']);
$router->post('/admin/products', [AdminController::class, 'saveProduct']);
$router->post('/admin/wallet-adjust', [AdminController::class, 'adjustWallet']);
$router->get('/admin/funds', [AdminController::class, 'funds']);
$router->post('/admin/funds/deposit/{id}', [AdminController::class, 'confirmDeposit']);
$router->post('/admin/funds/withdraw/{id}', [AdminController::class, 'confirmWithdraw']);
$router->post('/admin/funds/inject-own', [AdminController::class, 'injectOwn']);
$router->post('/admin/funds/recalcular-aum', [AdminController::class, 'recalcAum']);
$router->post('/admin/loans/{id}/auto-invest', [AdminController::class, 'runAutoInvest']);
$router->post('/admin/mark-overdue', [AdminController::class, 'runOverdue']);
$router->get('/admin/audit.csv', [AdminController::class, 'exportAuditCsv']);
$router->get('/admin/afip-rentas.csv', [AdminController::class, 'exportAfipRentas']);
$router->get('/admin/users.csv', [AdminController::class, 'exportUsersCsv']);
$router->get('/admin/funds.csv', [AdminController::class, 'exportFundsCsv']);
$router->get('/investments/afip-rentas.csv', [LoanController::class, 'exportMyAfipRentas']);

// Contratos y verificación de integridad
$router->get('/contract/verify/{hash}', [\App\Controllers\LoanController::class, 'verifyContract']);
$router->get('/loans/{id}/contract', [\App\Controllers\LoanController::class, 'downloadContract']);

// Operación Mercado Pago
$router->get('/admin/mercadopago', [MercadoPagoAdminController::class, 'index']);
$router->post('/admin/mercadopago', [MercadoPagoAdminController::class, 'saveSettings']);
$router->post('/admin/mercadopago/conciliar', [MercadoPagoAdminController::class, 'reconcile']);
$router->post('/admin/mercadopago/sync/{payment_id}', [MercadoPagoAdminController::class, 'syncPayment']);
$router->post('/admin/mercadopago/refund/{id}', [MercadoPagoAdminController::class, 'refund']);
$router->post('/admin/mercadopago/payout/{id}', [MercadoPagoAdminController::class, 'markPayoutSent']);
$router->get('/admin/mercadopago/payouts.csv', [MercadoPagoAdminController::class, 'exportPayouts']);

$router->get('/api/v1/health', [BankApiController::class, 'health']);
$router->get('/api/docs', [BankApiController::class, 'docs']);
$router->post('/api/v1/login/jwt', [BankApiController::class, 'login']);
$router->get('/api/v1/me', [BankApiController::class, 'me']);
$router->get('/api/v1/banks/{bank_id}/accounts/{view_id}', [BankApiController::class, 'listAccounts']);
$router->get('/api/v1/banks/{bank_id}/accounts/{account_id}/{view_id}/transactions', [BankApiController::class, 'transactions']);
$router->get('/api/v1/accounts/cbu/{cbu}', [BankApiController::class, 'ownershipByCbu']);
$router->get('/api/v1/accounts/alias/{alias}', [BankApiController::class, 'ownershipByAlias']);
$router->post('/api/v1/banks/{bank_id}/accounts/{account_id}/{view_id}/transaction-request-types/TRANSFER/transaction-requests', [BankApiController::class, 'transfer']);
$router->post('/api/v1/banks/{bank_id}/accounts/{account_id}/{view_id}/transaction-request-types/DEBIN/transaction-requests', [BankApiController::class, 'debin']);
$router->get('/api/v1/banks/{bank_id}/accounts/{account_id}/{view_id}/transaction-request-types/CHECK', [BankApiController::class, 'listEcheqs']);
$router->post('/api/v1/banks/{bank_id}/accounts/{account_id}/{view_id}/transaction-request-types/CHECK/transaction-requests', [BankApiController::class, 'issueEcheq']);
$router->post('/api/v1/debin/{debin_id}/approve', [BankApiController::class, 'debinApprove']);
$router->post('/api/v1/debin/{debin_id}/reject', [BankApiController::class, 'debinReject']);
$router->post('/api/v1/echeq/{echeq_id}/{action}', [BankApiController::class, 'echeqAction']);
$router->put('/api/v1/accounts/alias', [BankApiController::class, 'changeAlias']);

return $router;
