(function () {
    'use strict';

    var i18n = window.EAM_I18N || {};

    function setStatus(input, text, cls) {
        var field = input.closest('.field');
        if (!field) return;
        var status = field.querySelector('[data-status="' + input.name + '"]');
        if (!status) return;
        status.textContent = text || '';
        status.className = 'field-status' + (cls ? ' ' + cls : '');
    }

    function post(url, data) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: new URLSearchParams(data).toString()
        }).then(function (response) {
            return response.json();
        });
    }

    document.querySelectorAll('[data-check-username]').forEach(function (input) {
        var form = input.closest('form');
        var host = form ? form.querySelector('[name="host"]') : null;

        function check() {
            var username = input.value.trim().toLowerCase();
            if (!username || !host || !host.value) {
                setStatus(input, '');
                return;
            }
            setStatus(input, i18n.checking || '...');
            post('/api/check-username', {username: username, host: host.value}).then(function (data) {
                setStatus(input, data.message || '', data.status);
            }).catch(function () {
                setStatus(input, '', 'error');
            });
        }

        input.addEventListener('change', function () {
            input.value = input.value.trim().toLowerCase();
            check();
        });
        if (host) host.addEventListener('change', check);
    });

    document.querySelectorAll('[data-validate-email]').forEach(function (input) {
        function check() {
            var email = input.value.trim();
            if (!email) {
                setStatus(input, '');
                return;
            }
            setStatus(input, i18n.checking || '...');
            post('/api/validate-email', {email: email}).then(function (data) {
                setStatus(input, data.message || '', data.status);
            }).catch(function () {
                setStatus(input, '', 'error');
            });
        }
        input.addEventListener('change', check);
        input.addEventListener('blur', check);
    });

    function passwordScore(password) {
        if (!password) return -1;
        var common = ['password', 'password1', 'password123', '12345678', '123456789', 'qwerty', 'qwerty123', 'admin', 'admin123', 'letmein', 'welcome', 'monkey', 'dragon', 'iloveyou', 'sunshine', 'princess', 'football', 'baseball', 'abc123', 'qwertyuiop', '11111111', '00000000', 'passw0rd', 'p@ssw0rd'];
        var lower = password.toLowerCase();
        if (common.indexOf(lower) !== -1) return 0;
        if (/^(.)\1+$/.test(password)) return 0;
        if (/^(?:0123|1234|2345|3456|4567|5678|6789|abcd|bcde|cdef|qwer|asdf|zxcv)/i.test(password)) return 0;

        var pool = 0;
        if (/[a-z]/.test(password)) pool += 26;
        if (/[A-Z]/.test(password)) pool += 26;
        if (/[0-9]/.test(password)) pool += 10;
        if (/[^a-zA-Z0-9]/.test(password)) pool += 33;
        if (pool === 0) pool = 1;
        var entropy = password.length * Math.log(pool) / Math.log(2);
        var repeats = (password.match(/(.)\1{2,}/g) || []).length;
        if (repeats > 0) entropy -= 8 * repeats;
        if (/^(?:\d+|[a-zA-Z]+)$/.test(password)) entropy -= 8;

        if (entropy < 28) return 0;
        if (entropy < 36) return 1;
        if (entropy < 60) return 2;
        if (entropy < 80) return 3;
        return 4;
    }

    document.querySelectorAll('[data-password-strength]').forEach(function (input) {
        var field = input.closest('.field');
        var meter = field ? field.querySelector('[data-strength]') : null;
        if (!meter) return;
        input.addEventListener('input', function () {
            var score = passwordScore(input.value);
            if (score < 0) {
                meter.textContent = '';
                meter.className = 'strength';
                meter.removeAttribute('data-score');
                return;
            }
            meter.textContent = i18n['password_strength_' + score] || '';
            meter.className = 'strength s' + score;
            meter.setAttribute('data-score', String(score));
        });
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-captcha-reload]');
        if (!button) return;
        event.preventDefault();
        fetch('/api/captcha', {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
            .then(function (response) { return response.json(); })
            .then(function (data) {
                var current = document.getElementById('captcha');
                if (current && data && typeof data.html === 'string' && data.html !== '') {
                    current.outerHTML = data.html;
                }
            })
            .catch(function () {});
    });
})();
