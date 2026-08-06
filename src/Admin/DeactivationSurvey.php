<?php

namespace Appneck\Sdk\Admin;

use Appneck\Sdk\Survey;

/**
 * The deactivation survey as a site owner experiences it: a modal that
 * opens when they click Deactivate on the plugins screen.
 *
 * ## Why the plugins-screen intercept, and not a page of our own
 *
 * This is the pattern every commercial WordPress SDK uses (Freemius,
 * WPMU DEV, Yoast's variants), and for a good reason: the only moment a
 * site owner will ever tell you why they are leaving is the moment they
 * are leaving. A "please tell us why" screen after the fact is a screen
 * they never load, and a deactivated plugin cannot render one anyway —
 * WordPress stops loading it entirely.
 *
 * Implemented as a JS-driven modal over the existing screen rather than a
 * redirect to an interstitial page, which matters more than it looks:
 * the Deactivate link is a nonced GET, so bouncing through a page of our
 * own would mean carrying that nonce through a second request and handing
 * back a "continue" link — a deactivation flow we now own and can break.
 * Intercepting the click instead leaves WordPress's own link untouched and
 * unfollowed until the owner is done, and following it later is a plain
 * navigation to the URL WordPress itself put in the page.
 *
 * ## Deactivation always wins
 *
 * Every exit from the modal ends in deactivation except the explicit
 * cancel. Submit and Skip both navigate to the original link; a failed
 * submission navigates anyway; a fetch that returns nothing skips the
 * modal entirely. The survey is feedback collection, never a gate — same
 * principle as S4.2's "activation never waits on the API", applied to the
 * other end of the lifecycle.
 *
 * The close button, Escape and the backdrop are a CANCEL, not a skip:
 * they leave the plugin active. Treating "I closed the box" as "yes,
 * deactivate" would mean a stray Escape key uninstalling something.
 *
 * ## How a failed submission is communicated: it isn't, and it can't be
 *
 * If the POST fails the modal proceeds to deactivate and the failure is
 * only recorded through the SDK's Logger (opt-in, off by default). There
 * is no user-facing message, and that is forced rather than chosen: the
 * only place to show one would be the admin screen loaded *after*
 * deactivation, and a deactivated plugin runs no code, so it cannot
 * render a notice. The alternatives were worse — holding the modal open
 * to apologise makes a failure of ours into a delay of theirs, and
 * refusing to deactivate over a lost survey is the exact behaviour that
 * gets an SDK ripped out of a plugin. So the site owner's action
 * completes and Appneck absorbs the loss quietly.
 *
 * ## Assets are inline, on one screen
 *
 * No enqueued .js/.css file, because a bundled SDK cannot know its own
 * URL: it may sit in vendor/, in a custom directory, or in a mu-plugin,
 * and plugins_url() guesses would break for someone. The markup, style
 * and script print in the footer of plugins.php only — one screen, one
 * page load, no extra requests and no path assumptions.
 */
final class DeactivationSurvey {

	const ACTION_PREFIX = 'appneck_sdk_survey_';

	/** @var Survey */
	private $survey;

	/** @var string Per-product suffix, shared with the survey's storage. */
	private $key;

	/** @var string|null plugin_basename() of the host plugin. */
	private $plugin_basename = null;

	/** @var string */
	private $product_name = 'this plugin';

	public function __construct( Survey $survey, $key, $plugin_file = null, array $options = array() ) {
		$this->survey = $survey;
		$this->key    = (string) $key;

		if ( null !== $plugin_file && function_exists( 'plugin_basename' ) ) {
			$this->plugin_basename = plugin_basename( $plugin_file );
		}

		if ( isset( $options['product_name'] ) && '' !== $options['product_name'] ) {
			$this->product_name = (string) $options['product_name'];
		}
	}

	/** @param string $name */
	public function set_product_name( $name ) {
		if ( '' !== (string) $name ) {
			$this->product_name = (string) $name;
		}

		return $this;
	}

	/** @param string $basename e.g. "acme-bookings/acme-bookings.php". */
	public function set_plugin_basename( $basename ) {
		$this->plugin_basename = (string) $basename;

		return $this;
	}

	public function action() {
		return self::ACTION_PREFIX . $this->key;
	}

	public function register_hooks() {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		// The footer of the plugins screen only. Not admin_footer
		// generally: there is no Deactivate link to intercept anywhere
		// else, so printing a modal there would be markup on every admin
		// page for nothing.
		add_action( 'admin_footer-plugins.php', array( $this, 'render' ) );
		add_action( 'wp_ajax_' . $this->action(), array( $this, 'handle_ajax' ) );
	}

	// -----------------------------------------------------------------
	// Rendering
	// -----------------------------------------------------------------

	/**
	 * Prints the (empty) modal shell, its style, and the script that fills
	 * it. The questions are NOT printed here — they are fetched by the
	 * script when the modal opens, so the plugins screen itself never
	 * waits on anything.
	 */
	public function render() {
		if ( ! $this->can_render() ) {
			return;
		}

		$config = array(
			'action'      => $this->action(),
			'nonce'       => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( $this->action() ) : '',
			'ajaxUrl'     => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '/wp-admin/admin-ajax.php',
			'plugin'      => (string) $this->plugin_basename,
			'productName' => $this->product_name,
			'maxLength'   => Survey::TEXT_AREA_MAX_LENGTH,
			'strings'     => array(
				'heading' => 'Before you go',
				'intro'   => 'If you have a moment, telling us why helps ' . $this->product_name . ' get better.',
				'submit'  => 'Submit & deactivate',
				'skip'    => 'Skip & deactivate',
				'cancel'  => 'Cancel',
				'close'   => 'Close',
				'sending' => 'Sending…',
				'loading' => 'Loading…',
			),
		);

		echo '<div id="appneck-sdk-survey-' . esc_attr( $this->key ) . '" class="appneck-sdk-survey" hidden>';
		echo '<div class="appneck-sdk-survey__backdrop" data-appneck-cancel></div>';
		echo '<div class="appneck-sdk-survey__dialog" role="dialog" aria-modal="true" aria-labelledby="appneck-sdk-survey-title-' . esc_attr( $this->key ) . '">';
		echo '<button type="button" class="appneck-sdk-survey__close" data-appneck-cancel aria-label="' . esc_attr( $config['strings']['close'] ) . '">&times;</button>';
		echo '<h2 id="appneck-sdk-survey-title-' . esc_attr( $this->key ) . '">' . esc_html( $config['strings']['heading'] ) . '</h2>';
		echo '<p class="appneck-sdk-survey__intro">' . esc_html( $config['strings']['intro'] ) . '</p>';
		echo '<form class="appneck-sdk-survey__form" novalidate><div data-appneck-fields></div></form>';
		echo '<p class="appneck-sdk-survey__actions">';
		echo '<button type="button" class="button button-primary" data-appneck-submit>' . esc_html( $config['strings']['submit'] ) . '</button> ';
		echo '<button type="button" class="button" data-appneck-skip>' . esc_html( $config['strings']['skip'] ) . '</button> ';
		echo '<button type="button" class="button-link" data-appneck-cancel>' . esc_html( $config['strings']['cancel'] ) . '</button>';
		echo '</p>';
		echo '</div></div>';

		$this->render_style();
		$this->render_script( $config );
	}

	private function render_style() {
		// Scoped to this component and leaning on core's own button
		// classes for anything a site owner would recognise, so the modal
		// looks like part of wp-admin rather than like a guest.
		echo '<style>
.appneck-sdk-survey{position:fixed;inset:0;z-index:100050;display:flex;align-items:center;justify-content:center}
.appneck-sdk-survey[hidden]{display:none}
.appneck-sdk-survey__backdrop{position:absolute;inset:0;background:rgba(0,0,0,.6)}
.appneck-sdk-survey__dialog{position:relative;background:#fff;color:#1d2327;max-width:520px;width:calc(100% - 32px);max-height:calc(100vh - 64px);overflow-y:auto;padding:24px;border-radius:4px;box-shadow:0 10px 40px rgba(0,0,0,.3)}
.appneck-sdk-survey__dialog h2{margin:0 24px 4px 0;font-size:18px;line-height:1.3}
.appneck-sdk-survey__intro{margin:0 0 16px;color:#50575e}
.appneck-sdk-survey__close{position:absolute;top:8px;right:8px;background:none;border:0;font-size:22px;line-height:1;cursor:pointer;color:#787c82;padding:4px 8px}
.appneck-sdk-survey__question{margin:0 0 18px}
.appneck-sdk-survey__question legend,.appneck-sdk-survey__question .appneck-sdk-survey__label{display:block;font-weight:600;margin:0 0 6px;padding:0}
.appneck-sdk-survey__question fieldset{border:0;padding:0;margin:0}
.appneck-sdk-survey__question label{display:block;margin:0 0 4px;font-weight:400}
.appneck-sdk-survey__question textarea{width:100%;min-height:80px}
.appneck-sdk-survey__question select{max-width:100%}
.appneck-sdk-survey__rating{display:flex;gap:12px;flex-wrap:wrap}
.appneck-sdk-survey__rating label{display:flex;align-items:center;gap:4px;margin:0}
.appneck-sdk-survey__error{color:#d63638;margin:4px 0 0;font-size:13px}
.appneck-sdk-survey__actions{margin:20px 0 0;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
</style>';
	}

	/** @param array<string, mixed> $config */
	private function render_script( array $config ) {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $config ) : json_encode( $config );

		// No template literals, no arrow functions, no fetch(): this runs
		// in whatever browser the site owner's host machine has, and
		// wp-admin still supports old ones. XMLHttpRequest and ES5 keep it
		// working without a build step or a polyfill.
		echo '<script>(function(){
var cfg = ' . $json . ';
var root = document.getElementById("appneck-sdk-survey-" + ' . json_encode( $this->key ) . ');
if (!root || !cfg.plugin) { return; }

var fields = root.querySelector("[data-appneck-fields]");
var submitButton = root.querySelector("[data-appneck-submit]");
var skipButton = root.querySelector("[data-appneck-skip]");
var target = null;
var questions = [];
var lastFocus = null;

function deactivate() {
	if (target) { window.location.href = target; }
}

function close() {
	root.setAttribute("hidden", "hidden");
	target = null;
	if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
}

function open() {
	root.removeAttribute("hidden");
	var focusable = root.querySelector("input, select, textarea, button");
	if (focusable && focusable.focus) { focusable.focus(); }
}

function esc(text) {
	var div = document.createElement("div");
	div.appendChild(document.createTextNode(text == null ? "" : String(text)));
	return div.innerHTML;
}

function post(op, payload, done) {
	var xhr = new XMLHttpRequest();
	xhr.open("POST", cfg.ajaxUrl, true);
	xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8");
	xhr.onreadystatechange = function () {
		if (xhr.readyState !== 4) { return; }
		var parsed = null;
		try { parsed = JSON.parse(xhr.responseText); } catch (e) { parsed = null; }
		done(xhr.status >= 200 && xhr.status < 300, parsed);
	};
	var body = "action=" + encodeURIComponent(cfg.action) +
		"&nonce=" + encodeURIComponent(cfg.nonce) +
		"&op=" + encodeURIComponent(op) +
		"&answers=" + encodeURIComponent(JSON.stringify(payload || {}));
	xhr.send(body);
}

function renderQuestions() {
	var html = "";
	for (var i = 0; i < questions.length; i++) {
		var q = questions[i];
		var name = "appneck_q_" + i;
		var choices = (q.options && q.options.choices) || [];
		var body = "";
		if (q.type === "radio" || q.type === "checkbox") {
			body += "<fieldset><legend>" + esc(q.text) + "</legend>";
			for (var c = 0; c < choices.length; c++) {
				body += \'<label><input type="\' + (q.type === "radio" ? "radio" : "checkbox") + \'" name="\' + name + \'" value="\' + esc(choices[c]) + \'"> \' + esc(choices[c]) + "</label>";
			}
			body += "</fieldset>";
		} else if (q.type === "dropdown") {
			body += \'<label class="appneck-sdk-survey__label" for="\' + name + \'">\' + esc(q.text) + "</label>";
			body += \'<select id="\' + name + \'" name="\' + name + \'"><option value="">&mdash;</option>\';
			for (var d = 0; d < choices.length; d++) {
				body += \'<option value="\' + esc(choices[d]) + \'">\' + esc(choices[d]) + "</option>";
			}
			body += "</select>";
		} else if (q.type === "rating") {
			var max = (q.options && q.options.max) ? parseInt(q.options.max, 10) : 5;
			body += "<fieldset><legend>" + esc(q.text) + \'</legend><div class="appneck-sdk-survey__rating">\';
			for (var r = 1; r <= max; r++) {
				body += \'<label><input type="radio" name="\' + name + \'" value="\' + r + \'"> \' + r + "</label>";
			}
			body += "</div></fieldset>";
		} else if (q.type === "text_area") {
			body += \'<label class="appneck-sdk-survey__label" for="\' + name + \'">\' + esc(q.text) + "</label>";
			body += \'<textarea id="\' + name + \'" name="\' + name + \'" maxlength="\' + cfg.maxLength + \'"></textarea>\';
		}
		// An unknown type — a newer server than this copy of the SDK —
		// produces no body and is skipped rather than guessed at.
		if (body === "") { continue; }
		html += \'<div class="appneck-sdk-survey__question" data-appneck-question="\' + esc(q.id) + \'" data-appneck-type="\' + esc(q.type) + \'">\' + body + "</div>";
	}
	fields.innerHTML = html;
}

function collect() {
	var values = {};
	var blocks = fields.querySelectorAll("[data-appneck-question]");
	for (var i = 0; i < blocks.length; i++) {
		var block = blocks[i];
		var id = block.getAttribute("data-appneck-question");
		var type = block.getAttribute("data-appneck-type");
		if (type === "checkbox") {
			var checked = block.querySelectorAll("input:checked");
			var list = [];
			for (var c = 0; c < checked.length; c++) { list.push(checked[c].value); }
			if (list.length) { values[id] = list; }
		} else if (type === "radio" || type === "rating") {
			var one = block.querySelector("input:checked");
			if (one) { values[id] = one.value; }
		} else {
			var field = block.querySelector("select, textarea");
			if (field && field.value !== "") { values[id] = field.value; }
		}
	}
	return values;
}

function showErrors(errors) {
	var old = fields.querySelectorAll(".appneck-sdk-survey__error");
	for (var i = 0; i < old.length; i++) { old[i].parentNode.removeChild(old[i]); }
	var first = null;
	for (var id in errors) {
		if (!errors.hasOwnProperty(id)) { continue; }
		var block = fields.querySelector(\'[data-appneck-question="\' + id + \'"]\');
		if (!block) { continue; }
		var p = document.createElement("p");
		p.className = "appneck-sdk-survey__error";
		p.appendChild(document.createTextNode(errors[id]));
		block.appendChild(p);
		if (!first) { first = block; }
	}
	if (first && first.scrollIntoView) { first.scrollIntoView({ block: "nearest" }); }
}

function intercept(event) {
	var link = event.target;
	while (link && link.tagName !== "A") { link = link.parentNode; }
	if (!link || !link.href) { return; }
	if (link.href.indexOf("action=deactivate") === -1) { return; }
	if (decodeURIComponent(link.href).indexOf("plugin=" + cfg.plugin) === -1 &&
		link.href.indexOf("plugin=" + encodeURIComponent(cfg.plugin)) === -1) { return; }

	event.preventDefault();
	lastFocus = link;
	target = link.href;
	fields.innerHTML = "<p>" + esc(cfg.strings.loading) + "</p>";
	open();

	var href = link.href;

	post("questions", {}, function (ok, data) {
		if (!ok || !data || !data.success || !data.data || !data.data.questions || !data.data.questions.length) {
			// No survey configured, or we could not load it. Get out of
			// the way — this is the common case for most products.
			root.setAttribute("hidden", "hidden");
			window.location.href = href;
			return;
		}
		questions = data.data.questions;
		renderQuestions();
		open();
	});
}

document.addEventListener("click", function (event) {
	var el = event.target;
	while (el && el !== root && el.getAttribute) {
		if (el.hasAttribute("data-appneck-cancel")) { event.preventDefault(); close(); return; }
		el = el.parentNode;
	}
}, false);

document.addEventListener("keydown", function (event) {
	if ((event.key === "Escape" || event.key === "Esc") && !root.hasAttribute("hidden")) { close(); }
}, false);

document.addEventListener("click", intercept, false);

skipButton.addEventListener("click", function () { deactivate(); }, false);

submitButton.addEventListener("click", function () {
	var values = collect();
	var pending = target;
	submitButton.disabled = true;
	submitButton.textContent = cfg.strings.sending;

	post("submit", values, function (ok, data) {
		if (ok && data && !data.success && data.data && data.data.errors) {
			// Local validation said no. The one case where the modal stays
			// open, because it is fixable and nothing has been sent.
			submitButton.disabled = false;
			submitButton.textContent = cfg.strings.submit;
			showErrors(data.data.errors);
			return;
		}
		// Everything else — success, a server rejection, a dead network —
		// proceeds. The survey must never be the reason a deactivation
		// does not happen.
		target = pending;
		deactivate();
	});
}, false);
})();</script>';
	}

	// -----------------------------------------------------------------
	// AJAX
	// -----------------------------------------------------------------

	/**
	 * Both operations behind one action: `questions` fills the modal,
	 * `submit` sends it. One handler because they share the nonce, the
	 * capability check and the per-product action name.
	 *
	 * @return array<string, mixed>|null The payload sent, for tests. Null
	 *                                   when the request was refused.
	 */
	public function handle_ajax() {
		if ( ! $this->current_user_can_deactivate() ) {
			return $this->fail( 'You are not allowed to do that.', 403 );
		}

		if ( function_exists( 'check_ajax_referer' ) && ! check_ajax_referer( $this->action(), 'nonce', false ) ) {
			return $this->fail( 'That page has expired. Please reload and try again.', 403 );
		}

		$op = isset( $_POST['op'] ) ? (string) $_POST['op'] : '';

		if ( 'questions' === $op ) {
			return $this->send( array( 'questions' => $this->survey->questions() ) );
		}

		if ( 'submit' !== $op ) {
			return $this->fail( 'Unknown operation.', 400 );
		}

		$values    = $this->posted_answers();
		$questions = $this->survey->questions();
		$errors    = $this->survey->validate( $values, $questions );

		if ( array() !== $errors ) {
			// The one refusal the modal acts on rather than proceeding
			// through: nothing has been sent and the owner can fix it.
			return $this->fail_with( array( 'errors' => $errors ) );
		}

		$response = $this->survey->submit( $values, $questions );

		// Deliberately reports success even when the submission failed.
		// The modal's only remaining job is to let the deactivation
		// happen, and there is nothing the site owner could do with a
		// network error at this point — see the class doc.
		return $this->send(
			array(
				'submitted' => null !== $response && $response->ok(),
				'status'    => null !== $response ? $response->status() : 0,
			)
		);
	}

	/**
	 * The answers come over as one JSON string rather than nested form
	 * fields, because a checkbox answer is an array and a question id is a
	 * uuid — encoding that through form-field names is exactly where an
	 * injection or a silently-dropped value comes from. Decoded and
	 * shape-checked here; every value is re-validated against the real
	 * question before anything is sent.
	 *
	 * @return array<string, mixed>
	 */
	private function posted_answers() {
		if ( ! isset( $_POST['answers'] ) || ! is_string( $_POST['answers'] ) ) {
			return array();
		}

		// WordPress slashes $_POST; a free-text answer containing a quote
		// would otherwise arrive with a backslash in front of it, and the
		// JSON would fail to decode.
		$raw = function_exists( 'wp_unslash' ) ? wp_unslash( $_POST['answers'] ) : $_POST['answers'];

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$values = array();

		foreach ( $decoded as $id => $value ) {
			if ( ! is_string( $id ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$clean = array();

				foreach ( $value as $item ) {
					if ( is_scalar( $item ) ) {
						$clean[] = (string) $item;
					}
				}

				$values[ $id ] = $clean;

				continue;
			}

			if ( is_scalar( $value ) ) {
				$values[ $id ] = (string) $value;
			}
		}

		return $values;
	}

	// -----------------------------------------------------------------
	// Environment
	// -----------------------------------------------------------------

	/**
	 * `activate_plugins` — the same capability WordPress itself requires
	 * to deactivate a plugin. Anyone who can reach the link can answer the
	 * survey, and nobody else can; on multisite that correctly includes a
	 * single-site administrator.
	 */
	private function current_user_can_deactivate() {
		if ( ! function_exists( 'current_user_can' ) ) {
			return true;
		}

		return (bool) current_user_can( 'activate_plugins' );
	}

	private function can_render() {
		if ( ! function_exists( 'esc_html' ) || ! function_exists( 'esc_attr' ) ) {
			return false;
		}

		if ( null === $this->plugin_basename || '' === $this->plugin_basename ) {
			// Without the basename the script cannot tell which row's
			// Deactivate link is ours, and intercepting every plugin's
			// link would be unforgivable.
			return false;
		}

		return $this->current_user_can_deactivate();
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function send( array $data ) {
		if ( function_exists( 'wp_send_json_success' ) ) {
			wp_send_json_success( $data );
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function fail_with( array $data ) {
		if ( function_exists( 'wp_send_json_error' ) ) {
			wp_send_json_error( $data );
		}

		$this->denied = isset( $data['errors'] ) ? 'validation' : 'error';

		return $data;
	}

	/**
	 * @param string $message
	 * @return null
	 */
	private function fail( $message, $status ) {
		$this->denied = (string) $message;

		if ( function_exists( 'wp_send_json_error' ) ) {
			wp_send_json_error( array( 'message' => $message ), $status );
		}

		return null;
	}

	/**
	 * The last refusal, recorded only when WordPress's wp_send_json_*
	 * helpers are unavailable (this package's own tests, where they would
	 * otherwise exit) so a refusal stays observable.
	 *
	 * @var string|null
	 */
	public $denied = null;
}
