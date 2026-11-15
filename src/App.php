<?php
declare(strict_types=1);

namespace Eam;

use Eam\Captcha\CaptchaFactory;
use Eam\Captcha\CaptchaInterface;
use Eam\Ejabberd\ApiException;
use Eam\Ejabberd\Client;
use Eam\Http\Request;
use Eam\Http\Response;
use Eam\Http\Router;
use Eam\Http\Session;
use Eam\I18n\Translator;
use Eam\Mail\MailerFactory;
use Eam\Mail\MailerInterface;
use Eam\Service\AccountDeletionService;
use Eam\Service\EmailChangeService;
use Eam\Service\PasswordResetService;
use Eam\Service\RegistrationService;
use Eam\Service\Result;
use Eam\Storage\StorageFactory;
use Eam\Storage\StorageInterface;
use Eam\Support\Logger;
use Eam\Support\View;
use Eam\Validation\DisposableEmailChecker;
use Eam\Validation\Validator;
use Throwable;

final class App
{
    private Session $session;
    private Router $router;
    private Translator $translator;
    private View $view;
    private Logger $logger;
    private StorageInterface $storage;
    private Client $client;
    private MailerInterface $mailer;
    private CaptchaInterface $captcha;
    private DisposableEmailChecker $disposable;
    private RegistrationService $registration;
    private EmailChangeService $emailChange;
    private PasswordResetService $passwordReset;
    private AccountDeletionService $accountDeletion;
    private bool $booted = false;

    public function __construct(
        private readonly Config $config,
        private readonly Request $request
    ) {
        $this->session = new Session($config);
        $this->router = new Router();
    }

    public function run(): void
    {
        try {
            $this->session->start();
            $this->boot();
            $this->registerRoutes();
            $response = $this->router->dispatch($this->request);
        } catch (Throwable $e) {
            if ($this->booted) {
                $this->logger->error('Unhandled application error', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
            $response = $this->errorResponse($e);
        }
        $response->send();
    }

    private function boot(): void
    {
        $this->logger = Logger::fromConfig($this->config);
        $locale = Translator::negotiate($this->request, $this->config);
        $this->translator = new Translator(
            dirname(__DIR__) . '/lang',
            $locale,
            (array) $this->config->get('app.locales', ['en'])
        );
        $this->view = new View(
            $this->translator,
            $this->config,
            $this->request,
            $this->session,
            dirname(__DIR__) . '/templates'
        );
        $this->storage = StorageFactory::create($this->config, $this->logger);
        $this->client = new Client($this->config, $this->logger);
        $this->mailer = MailerFactory::create($this->config);
        $this->captcha = CaptchaFactory::create($this->config, $this->session, $this->translator, $this->logger);
        $this->disposable = new DisposableEmailChecker(
            $this->config,
            $this->logger,
            rtrim((string) $this->config->get('storage.files_path'), '/') . '/cache'
        );
        $this->registration = new RegistrationService(
            $this->config,
            $this->translator,
            $this->client,
            $this->storage,
            $this->mailer,
            $this->captcha,
            $this->view,
            $this->logger,
            $this->disposable
        );
        $this->emailChange = new EmailChangeService(
            $this->config,
            $this->translator,
            $this->client,
            $this->storage,
            $this->mailer,
            $this->captcha,
            $this->view,
            $this->logger,
            $this->disposable
        );
        $this->passwordReset = new PasswordResetService(
            $this->config,
            $this->translator,
            $this->client,
            $this->storage,
            $this->mailer,
            $this->captcha,
            $this->view,
            $this->logger,
            $this->disposable
        );
        $this->accountDeletion = new AccountDeletionService(
            $this->config,
            $this->translator,
            $this->client,
            $this->storage,
            $this->mailer,
            $this->captcha,
            $this->view,
            $this->logger,
            $this->disposable
        );
        $this->booted = true;

        if (random_int(1, 200) === 1) {
            try {
                $this->storage->cleanup();
            } catch (Throwable $e) {
                $this->logger->warning('Storage cleanup failed', ['error' => $e->getMessage()]);
            }
        }
    }

    private function registerRoutes(): void
    {
        $this->router->get('/', fn (Request $r): Response => $this->home($r));
        $this->router->get('/registration', fn (Request $r): Response => $this->registrationShow($r));
        $this->router->post('/registration', fn (Request $r): Response => $this->registrationSubmit($r));
        $this->router->get('/change-email', fn (Request $r): Response => $this->emailChangeShow($r));
        $this->router->post('/change-email', fn (Request $r): Response => $this->emailChangeSubmit($r));
        $this->router->get('/reset-password', fn (Request $r): Response => $this->resetPasswordShow($r));
        $this->router->post('/reset-password', fn (Request $r): Response => $this->resetPasswordSubmit($r));
        $this->router->get('/delete-account', fn (Request $r): Response => $this->deleteAccountShow($r));
        $this->router->post('/delete-account', fn (Request $r): Response => $this->deleteAccountSubmit($r));
        $this->router->get('/language', fn (Request $r): Response => $this->language($r));
        $this->router->post('/api/check-username', fn (Request $r): Response => $this->apiCheckUsername($r));
        $this->router->post('/api/validate-email', fn (Request $r): Response => $this->apiValidateEmail($r));
        $this->router->get('/api/captcha', fn (Request $r): Response => $this->apiCaptcha($r));
        $this->router->get('/health', fn (Request $r): Response => Response::json([
            'status' => 'ok',
            'time' => date('c'),
            'storage' => $this->storage::class,
        ]));
        $this->router->setNotFound(fn (Request $r): Response => $this->page('error', [
            'title' => $this->t('error.not_found.title'),
            'message' => $this->t('error.not_found.text'),
            'active' => '',
        ], 404));
    }

    private function home(Request $request): Response
    {
        $features = $this->translator->get('landing.features');
        return $this->page('landing', [
            'title' => $this->t('landing.title'),
            'active' => 'home',
            'hosts' => (array) $this->config->get('registration.hosts', []),
            'features' => is_array($features) ? $features : [],
        ]);
    }

    private function registrationShow(Request $request): Response
    {
        $code = trim((string) $request->query('code', ''));
        $captchaHtml = $this->captcha->render();
        if ($code !== '') {
            $result = $this->registration->activate($code, $this->ip());
            if ($result->isSuccess()) {
                return $this->page('registration', [
                    'title' => $this->t('form.registration.title'),
                    'active' => 'registration',
                    'showForm' => false,
                    'result' => $result,
                    'form' => [],
                    'hosts' => (array) $this->config->get('registration.hosts', []),
                    'captchaHtml' => '',
                ]);
            }
            return $this->page('registration', [
                'title' => $this->t('form.registration.title'),
                'active' => 'registration',
                'showForm' => true,
                'result' => $result,
                'form' => [],
                'hosts' => (array) $this->config->get('registration.hosts', []),
                'captchaHtml' => $captchaHtml,
            ]);
        }
        return $this->page('registration', [
            'title' => $this->t('form.registration.title'),
            'active' => 'registration',
            'showForm' => true,
            'result' => null,
            'form' => [],
            'hosts' => (array) $this->config->get('registration.hosts', []),
            'captchaHtml' => $captchaHtml,
        ]);
    }

    private function registrationSubmit(Request $request): Response
    {
        $input = $request->allInput();
        $base = [
            'title' => $this->t('form.registration.title'),
            'active' => 'registration',
            'showForm' => true,
            'form' => $input,
            'hosts' => (array) $this->config->get('registration.hosts', []),
        ];
        if (!$this->session->verifyCsrf($input['_csrf'] ?? null)) {
            return $this->page('registration', $base + [
                'result' => Result::blocked($this->t('error.csrf')),
                'captchaHtml' => $this->captcha->render(),
            ]);
        }
        if ($this->honeypotTripped($input)) {
            return $this->page('registration', $base + [
                'result' => Result::blocked($this->t('error.bot')),
                'captchaHtml' => $this->captcha->render(),
            ]);
        }
        $result = $this->registration->submit($input, $this->ip());
        if ($result->isSuccess()) {
            $this->session->flash('success', (string) $result->message);
            return Response::redirect(url('/registration'));
        }
        return $this->page('registration', $base + [
            'result' => $result,
            'captchaHtml' => $this->captcha->render(),
        ]);
    }

    private function emailChangeShow(Request $request): Response
    {
        $code = trim((string) $request->query('code', ''));
        if ($code !== '') {
            $shown = $this->emailChange->showCode($code);
            if ($shown->isSuccess()) {
                return $this->page('change_email', [
                    'title' => $this->t('form.change_email.title'),
                    'active' => 'change_email',
                    'mode' => 'confirm',
                    'result' => null,
                    'token' => $code,
                    'user' => $shown->data['user'] ?? '',
                    'host' => $shown->data['host'] ?? '',
                    'email' => $shown->data['email'] ?? '',
                    'form' => [],
                    'captchaHtml' => '',
                ]);
            }
            return $this->emailChangeForm($shown);
        }
        return $this->emailChangeForm(null);
    }

    private function emailChangeSubmit(Request $request): Response
    {
        $input = $request->allInput();
        $action = (string) ($input['action'] ?? 'request');
        $code = trim((string) ($input['code'] ?? ''));
        if (!$this->session->verifyCsrf($input['_csrf'] ?? null)) {
            return $this->emailChangeForm(Result::blocked($this->t('error.csrf')), $input);
        }
        if ($action === 'confirm') {
            $result = $this->emailChange->confirm($code);
            if ($result->isSuccess()) {
                $this->session->flash('success', (string) $result->message);
                return Response::redirect(url('/change-email'));
            }
            $shown = $this->emailChange->showCode($code);
            if ($shown->isSuccess()) {
                return $this->page('change_email', [
                    'title' => $this->t('form.change_email.title'),
                    'active' => 'change_email',
                    'mode' => 'confirm',
                    'result' => $result,
                    'token' => $code,
                    'user' => $shown->data['user'] ?? '',
                    'host' => $shown->data['host'] ?? '',
                    'email' => $shown->data['email'] ?? '',
                    'form' => [],
                    'captchaHtml' => '',
                ]);
            }
            return $this->emailChangeForm($result, $input);
        }
        if ($this->honeypotTripped($input)) {
            return $this->emailChangeForm(Result::blocked($this->t('error.bot')), $input);
        }
        $result = $this->emailChange->submitRequest($input, $this->ip());
        if ($result->isSuccess()) {
            $this->session->flash('success', (string) $result->message);
            return Response::redirect(url('/change-email'));
        }
        return $this->emailChangeForm($result, $input);
    }

    private function emailChangeForm(?Result $result, array $form = []): Response
    {
        return $this->page('change_email', [
            'title' => $this->t('form.change_email.title'),
            'active' => 'change_email',
            'mode' => 'form',
            'result' => $result,
            'token' => '',
            'user' => '',
            'host' => '',
            'email' => '',
            'form' => $form,
            'captchaHtml' => $this->captcha->render(),
        ]);
    }

    private function resetPasswordShow(Request $request): Response
    {
        $code = trim((string) $request->query('code', ''));
        if ($code !== '') {
            $shown = $this->passwordReset->showCode($code);
            if ($shown->isSuccess()) {
                return $this->page('reset_password', [
                    'title' => $this->t('form.reset_password.title'),
                    'active' => 'reset_password',
                    'mode' => 'confirm',
                    'result' => null,
                    'token' => $code,
                    'user' => $shown->data['user'] ?? '',
                    'host' => $shown->data['host'] ?? '',
                    'form' => [],
                    'captchaHtml' => '',
                ]);
            }
            return $this->resetPasswordForm($shown);
        }
        return $this->resetPasswordForm(null);
    }

    private function resetPasswordSubmit(Request $request): Response
    {
        $input = $request->allInput();
        $action = (string) ($input['action'] ?? 'request');
        $code = trim((string) ($input['code'] ?? ''));
        if (!$this->session->verifyCsrf($input['_csrf'] ?? null)) {
            return $this->resetPasswordForm(Result::blocked($this->t('error.csrf')), $input);
        }
        if ($action === 'confirm') {
            $password = (string) ($input['password'] ?? '');
            $result = $this->passwordReset->confirm($code, $password, $this->ip());
            if ($result->isSuccess()) {
                $this->session->flash('success', (string) $result->message);
                return Response::redirect(url('/reset-password'));
            }
            $shown = $this->passwordReset->showCode($code);
            if ($shown->isSuccess()) {
                return $this->page('reset_password', [
                    'title' => $this->t('form.reset_password.title'),
                    'active' => 'reset_password',
                    'mode' => 'confirm',
                    'result' => $result,
                    'token' => $code,
                    'user' => $shown->data['user'] ?? '',
                    'host' => $shown->data['host'] ?? '',
                    'form' => [],
                    'captchaHtml' => '',
                ]);
            }
            return $this->resetPasswordForm($result, $input);
        }
        if ($this->honeypotTripped($input)) {
            return $this->resetPasswordForm(Result::blocked($this->t('error.bot')), $input);
        }
        $result = $this->passwordReset->submitRequest($input, $this->ip());
        if ($result->isSuccess()) {
            $this->session->flash('success', (string) $result->message);
            return Response::redirect(url('/reset-password'));
        }
        return $this->resetPasswordForm($result, $input);
    }

    private function resetPasswordForm(?Result $result, array $form = []): Response
    {
        return $this->page('reset_password', [
            'title' => $this->t('form.reset_password.title'),
            'active' => 'reset_password',
            'mode' => 'form',
            'result' => $result,
            'token' => '',
            'user' => '',
            'host' => '',
            'form' => $form,
            'captchaHtml' => $this->captcha->render(),
        ]);
    }

    private function deleteAccountShow(Request $request): Response
    {
        $code = trim((string) $request->query('code', ''));
        if ($code !== '') {
            $shown = $this->accountDeletion->showCode($code);
            if ($shown->isSuccess()) {
                return $this->page('delete_account', [
                    'title' => $this->t('form.delete_account.title'),
                    'active' => 'delete_account',
                    'mode' => 'confirm',
                    'result' => null,
                    'token' => $code,
                    'user' => $shown->data['user'] ?? '',
                    'host' => $shown->data['host'] ?? '',
                    'form' => [],
                    'captchaHtml' => '',
                ]);
            }
            return $this->deleteAccountForm($shown);
        }
        return $this->deleteAccountForm(null);
    }

    private function deleteAccountSubmit(Request $request): Response
    {
        $input = $request->allInput();
        $action = (string) ($input['action'] ?? 'request');
        $code = trim((string) ($input['code'] ?? ''));
        if (!$this->session->verifyCsrf($input['_csrf'] ?? null)) {
            return $this->deleteAccountForm(Result::blocked($this->t('error.csrf')), $input);
        }
        if ($action === 'confirm') {
            $password = (string) ($input['password'] ?? '');
            $result = $this->accountDeletion->confirm($code, $password);
            if ($result->isSuccess()) {
                $this->session->flash('success', (string) $result->message);
                return Response::redirect(url('/delete-account'));
            }
            $shown = $this->accountDeletion->showCode($code);
            if ($shown->isSuccess()) {
                return $this->page('delete_account', [
                    'title' => $this->t('form.delete_account.title'),
                    'active' => 'delete_account',
                    'mode' => 'confirm',
                    'result' => $result,
                    'token' => $code,
                    'user' => $shown->data['user'] ?? '',
                    'host' => $shown->data['host'] ?? '',
                    'form' => [],
                    'captchaHtml' => '',
                ]);
            }
            return $this->deleteAccountForm($result, $input);
        }
        if ($this->honeypotTripped($input)) {
            return $this->deleteAccountForm(Result::blocked($this->t('error.bot')), $input);
        }
        $result = $this->accountDeletion->submitRequest($input, $this->ip());
        if ($result->isSuccess()) {
            $this->session->flash('success', (string) $result->message);
            return Response::redirect(url('/delete-account'));
        }
        return $this->deleteAccountForm($result, $input);
    }

    private function deleteAccountForm(?Result $result, array $form = []): Response
    {
        return $this->page('delete_account', [
            'title' => $this->t('form.delete_account.title'),
            'active' => 'delete_account',
            'mode' => 'form',
            'result' => $result,
            'token' => '',
            'user' => '',
            'host' => '',
            'form' => $form,
            'captchaHtml' => $this->captcha->render(),
        ]);
    }

    private function language(Request $request): Response
    {
        $to = (string) $request->query('to', '');
        $return = (string) $request->query('return', '/');
        if (!in_array($to, (array) $this->config->get('app.locales', []), true)) {
            return Response::redirect(url('/'));
        }
        if ($return === '' || !str_starts_with($return, '/') || str_starts_with($return, '//') || str_contains($return, '\\')) {
            $return = '/';
        }
        setcookie('eam_lang', $to, [
            'expires' => time() + 31536000,
            'path' => '/',
            'secure' => $request->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        return Response::redirect(url($return));
    }

    private function apiCheckUsername(Request $request): Response
    {
        if ($this->apiRateLimited()) {
            return Response::json(['status' => 'error', 'message' => $this->t('error.rate_limited')], 429);
        }
        $username = Validator::normalizeUsername((string) $request->input('username', ''));
        $host = strtolower(trim((string) $request->input('host', '')));
        $error = Validator::validateUsername($username, $this->config);
        if ($error !== null) {
            return Response::json(['status' => 'blocked', 'message' => $this->t('error.' . $error)]);
        }
        if (!Validator::hostAllowed($host, (array) $this->config->get('registration.hosts', []))) {
            return Response::json(['status' => 'blocked', 'message' => $this->t('error.host_invalid')]);
        }
        try {
            if ($this->client->checkAccount($username, $host)) {
                return Response::json(['status' => 'blocked', 'message' => $this->t('error.username_taken')]);
            }
        } catch (ApiException $e) {
            $this->logger->error('check-username API failed', ['status' => $e->status]);
            return Response::json(['status' => 'error', 'message' => $this->t('error.server')]);
        }
        return Response::json(['status' => 'success', 'message' => $this->t('message.username_available')]);
    }

    private function apiValidateEmail(Request $request): Response
    {
        if ($this->apiRateLimited()) {
            return Response::json(['status' => 'error', 'message' => $this->t('error.rate_limited')], 429);
        }
        $email = trim((string) $request->input('email', ''));
        $error = Validator::validateEmail($email, $this->config, $this->disposable);
        if ($error !== null) {
            return Response::json(['status' => 'blocked', 'message' => $this->t('error.' . $error)]);
        }
        return Response::json(['status' => 'success', 'message' => '']);
    }

    private function apiCaptcha(Request $request): Response
    {
        return Response::json($this->captcha->reload());
    }

    private function apiRateLimited(): bool
    {
        $key = 'api:ip:' . $this->ip();
        if ($this->storage->isRateLimited($key, 120)) {
            return true;
        }
        $this->storage->hitRate($key, 60);
        return false;
    }

    private function honeypotTripped(array $input): bool
    {
        if (!(bool) $this->config->get('security.honeypot', true)) {
            return false;
        }
        return trim((string) ($input['website'] ?? '')) !== '';
    }

    private function ip(): string
    {
        return $this->request->ip((array) $this->config->get('security.trusted_proxies', []));
    }

    private function t(string $key, array $params = []): string
    {
        $value = $this->translator->get($key, $params);
        return is_string($value) ? $value : $key;
    }

    private function page(string $template, array $data, int $status = 200): Response
    {
        $data += [
            'active' => '',
            'returnTo' => $this->currentReturn(),
            'csrf' => $this->session->csrfToken(),
        ];
        return Response::html($this->view->render($template, $data), $status);
    }

    private function currentReturn(): string
    {
        $query = $this->request->queryAll();
        unset($query['lang']);
        $path = $this->request->path();
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return $qs === '' ? $path : $path . '?' . $qs;
    }

    private function errorResponse(Throwable $e): Response
    {
        $message = $this->config->isDebug() ? $e->getMessage() : $this->t('error.unexpected');
        if ($this->booted) {
            return $this->page('error', [
                'title' => $this->t('error.server_title'),
                'message' => $message,
                'active' => '',
            ], 500);
        }
        return Response::text($message, 500);
    }
}
