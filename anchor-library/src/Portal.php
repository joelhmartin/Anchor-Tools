<?php
/**
 * Library front end: the three portal shortcodes, the assets they load and the AnchorFM / AnchorAP script globals.
 *
 * Ported from Anchor Private File Manager 2.17.0 with markup, shortcode names, script handles, localized globals
 * (AnchorFM, AnchorAP) and the nonce unchanged. Only the asset URLs moved, to anchor-library/assets/.
 *
 * @package Anchor\Library
 */

namespace Anchor\Library;

if ( ! \defined( 'ABSPATH' ) ) { exit; }

class Portal {

	/** The portal markup is a single-page app; a second shortcode on the same page renders nothing. */
	private static bool $portal_rendered = false;

	/** Forget that the portal rendered (tests, and long-lived workers that serve several pages per process). */
	public static function reset(): void {
		self::$portal_rendered = false;
	}

	public static function register(): void {
		\add_shortcode( 'anchor_file_manager', [ self::class, 'render_file_manager' ] );
		\add_shortcode( 'anchor_account_portal', [ self::class, 'render_account_portal' ] );
		\add_shortcode( 'anchor_documents_portal', [ self::class, 'render_documents_portal' ] );
		\add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
	}

	public static function enqueue_assets() {
		if (!\is_user_logged_in()) return;
		if (!self::should_enqueue_assets()) return;
		self::do_enqueue_assets();
	}

	private static function do_enqueue_assets() {
		if (\wp_script_is('anchor-file-manager', 'enqueued')) return;

		// Shared loader: serves the release build's .min siblings (unless SCRIPT_DEBUG) and versions by file mtime.
		$css_ver = self::asset_ver('anchor-library/assets/css/file-manager.css');
		$ap_css_ver = self::asset_ver('anchor-library/assets/css/account-portal.css');
		$js_ver = self::asset_ver('anchor-library/assets/js/file-manager.js');
		$portal_js_ver = self::asset_ver('anchor-library/assets/js/account-documents.js');

		\wp_enqueue_style('dashicons');
		\wp_enqueue_style(
			'anchor-file-manager',
			\Anchor_Asset_Loader::url('anchor-library/assets/css/file-manager.css'),
			[],
			$css_ver
		);
		\wp_enqueue_script(
			'anchor-file-manager',
			\Anchor_Asset_Loader::url('anchor-library/assets/js/file-manager.js'),
			['jquery'],
			$js_ver,
			true
		);

		$user = \wp_get_current_user();
		$product_docs_id = (int) \get_option(Schema::OPT_PD_FOLDER_ID, 0);
		if ($product_docs_id === 0 && \current_user_can('administrator')) {
			$product_docs_id = (int) Schema::ensure_product_docs_folder();
		}

		\wp_enqueue_style(
			'anchor-account-portal',
			\Anchor_Asset_Loader::url('anchor-library/assets/css/account-portal.css'),
			['anchor-file-manager'],
			$ap_css_ver
		);
		\wp_enqueue_script(
			'anchor-documents-portal',
			\Anchor_Asset_Loader::url('anchor-library/assets/js/account-documents.js'),
			['jquery', 'anchor-file-manager'],
			$portal_js_ver,
			true
		);

		\wp_enqueue_script(
			'anchor-fm-vimeo-player',
			'https://player.vimeo.com/api/player.js',
			[],
			null,
			true
		);

		if (\current_user_can('administrator')) {
			\wp_enqueue_script(
				'anchor-fm-user-manager',
				\Anchor_Asset_Loader::url('anchor-library/assets/js/user-manager.js'),
				['jquery', 'anchor-file-manager'],
				self::asset_ver('anchor-library/assets/js/user-manager.js'),
				true
			);
		}

		\wp_localize_script('anchor-file-manager', 'AnchorFM', [
			'ajax' => \admin_url('admin-ajax.php'),
			'nonce' => \wp_create_nonce(Http::NONCE_ACTION),
			'isAdmin' => \current_user_can('administrator'),
			'vimeoEnabled' => true,
			'productDocsFolderId' => $product_docs_id,
			'user' => [
				'id' => \get_current_user_id(),
				'roles' => \array_values((array) $user->roles),
				'displayName' => $user->display_name,
			],
			'roles' => PermissionWrites::editable_roles(),
			// What the Users tab may assign: no staff roles.
			'userRoles' => Users::role_options(),
			'defaultRole' => (string) \get_option('default_role'),
			'i18n' => [
				'title' => \__('File Manager', 'anchor-schema'),
				'upload' => \__('Upload', 'anchor-schema'),
				'newFolder' => \__('New folder', 'anchor-schema'),
				'rename' => \__('Rename', 'anchor-schema'),
				'delete' => \__('Delete', 'anchor-schema'),
				'permissions' => \__('Permissions', 'anchor-schema'),
				'download' => \__('Download', 'anchor-schema'),
				'noFiles' => \__('No files here yet.', 'anchor-schema'),
				'noFolders' => \__('No folders.', 'anchor-schema'),
				'productDocs' => \__('Product Docs', 'anchor-schema'),
				'users' => \__('Users', 'anchor-schema'),
			],
		]);

		\wp_localize_script('anchor-documents-portal', 'AnchorAP', [
			'ajax' => \admin_url('admin-ajax.php'),
			'nonce' => \wp_create_nonce(Http::NONCE_ACTION),
			'user' => [
				'id' => \get_current_user_id(),
				'displayName' => $user->display_name,
				'email' => $user->user_email,
				'firstName' => $user->first_name,
				'lastName' => $user->last_name,
			],
			'hasWoo' => \function_exists('wc_get_orders'),
			'i18n' => [
				'orders' => \__('Orders', 'anchor-schema'),
				'account' => \__('Account', 'anchor-schema'),
				'security' => \__('Security', 'anchor-schema'),
				'downloads' => \__('Downloads', 'anchor-schema'),
				'files' => \__('Files', 'anchor-schema'),
			],
		]);
	}

	/** mtime of the asset the loader resolves (min or source); the schema version when the file is missing. */
	private static function asset_ver( string $relative ): string {
		$path = \Anchor_Asset_Loader::path($relative);
		return \file_exists($path) ? (string) \filemtime($path) : Schema::DB_VERSION;
	}

	public static function render_file_manager() {
		return self::render_documents_portal();
	}

	public static function render_account_portal() {
		return self::render_documents_portal();
	}

	/** The shortcode body: the whole portal shell. A component with its markup, so the long function is deliberate. */
	public static function render_documents_portal() {
		if (!\is_user_logged_in()) {
			return '<p>You must be logged in to access your documents.</p>';
		}

		if (self::$portal_rendered) {
			return '';
		}
		self::$portal_rendered = true;

		self::do_enqueue_assets();

		$user = \wp_get_current_user();

		\ob_start(); ?>
		<div class="afm aap" data-apfm data-afm>
			<div class="afm__frame">
				<aside class="afm__sidebar" aria-label="<?php \esc_attr_e('Account navigation and folders', 'anchor-schema'); ?>">
					<div class="afm__brand">
						<?php $logo = Settings::portal_logo_url(); if ($logo !== '') : ?>
						<img class="afm__brandMark" src="<?php echo \esc_url($logo); ?>" alt="" aria-hidden="true">
						<?php endif; ?>
						<div class="afm__brandText">
							<div class="afm__brandTitle"><?php \esc_html_e('My Account', 'anchor-schema'); ?></div>
							<div class="afm__brandSub"><?php echo \esc_html($user->display_name); ?></div>
						</div>
					</div>
					<nav class="aap__nav" aria-label="<?php \esc_attr_e('Sections', 'anchor-schema'); ?>">
						<button type="button" class="aap__navItem is-active" data-apfm-tab="files">
							<span class="dashicons dashicons-category" aria-hidden="true"></span>
							<?php \esc_html_e('Documents', 'anchor-schema'); ?>
						</button>
						<button type="button" class="aap__navItem" data-apfm-tab="orders">
							<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
							<?php \esc_html_e('Orders', 'anchor-schema'); ?>
						</button>
						<button type="button" class="aap__navItem" data-apfm-tab="downloads">
							<span class="dashicons dashicons-download" aria-hidden="true"></span>
							<?php \esc_html_e('Downloads', 'anchor-schema'); ?>
						</button>
						<?php if (\current_user_can('administrator')) : ?>
						<button type="button" class="aap__navItem" data-apfm-tab="product-docs">
							<span class="dashicons dashicons-portfolio" aria-hidden="true"></span>
							<?php \esc_html_e('Product Docs', 'anchor-schema'); ?>
						</button>
						<?php endif; ?>
						<?php if (\current_user_can('administrator')) : ?>
						<button type="button" class="aap__navItem" data-apfm-tab="users">
							<span class="dashicons dashicons-groups" aria-hidden="true"></span>
							<?php \esc_html_e('Users', 'anchor-schema'); ?>
						</button>
						<?php endif; ?>
						<button type="button" class="aap__navItem" data-apfm-tab="account">
							<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
							<?php \esc_html_e('Account', 'anchor-schema'); ?>
						</button>
						<button type="button" class="aap__navItem" data-apfm-tab="security">
							<span class="dashicons dashicons-shield" aria-hidden="true"></span>
							<?php \esc_html_e('Security', 'anchor-schema'); ?>
						</button>
						<a class="aap__navItem aap__navItem--link" href="<?php echo \esc_url(\wp_logout_url(\home_url('/'))); ?>">
							<span class="dashicons dashicons-exit" aria-hidden="true"></span>
							<?php \esc_html_e('Log out', 'anchor-schema'); ?>
						</a>
					</nav>
					<div class="afm__treeScroll">
						<div class="afm__tree" data-afm-tree></div>
					</div>
					<div class="afm__resizer" data-afm-resizer aria-hidden="true"></div>
				</aside>

				<main class="afm__main" aria-label="<?php \esc_attr_e('Account content', 'anchor-schema'); ?>">
					<?php
					/*
					 * Utility bar: admin-only actions, kept out of the way of the
					 * everyday header below. Every action here — including
					 * Upload — is administrator-gated server-side
					 * (can_user_upload_to_folder() is an administrator check),
					 * so no other role has anything to put in this bar and it
					 * is not rendered for them at all.
					 *
					 * The :has() rule in the CSS additionally collapses it on
					 * tabs where its files-only children are hidden, so an
					 * admin on the Account tab gets no empty strip.
					 */
					?>
					<?php if (\current_user_can('administrator')) : ?>
					<div class="afm__utilityBar" data-afm-utility-bar>
						<button type="button" class="afm__utilityLink" data-afm-action="new-folder" data-apfm-files-only>
							<span class="dashicons dashicons-plus" aria-hidden="true"></span>
							<?php \esc_html_e('New folder', 'anchor-schema'); ?>
						</button>
						<button type="button" class="afm__utilityLink" data-afm-action="new-link" data-apfm-files-only>
							<span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
							<?php \esc_html_e('New link', 'anchor-schema'); ?>
						</button>
						<button type="button" class="afm__utilityLink" data-afm-action="new-video" data-apfm-files-only>
							<span class="dashicons dashicons-video-alt3" aria-hidden="true"></span>
							<?php \esc_html_e('New video', 'anchor-schema'); ?>
						</button>
						<div class="afm__upload" data-apfm-upload hidden>
							<input type="file" multiple class="afm__fileInput" data-afm-file-input>
							<button type="button" class="afm__btn afm__btn--primary" data-afm-action="upload">
								<span class="dashicons dashicons-upload" aria-hidden="true"></span>
								<?php \esc_html_e('Upload', 'anchor-schema'); ?>
							</button>
						</div>
					</div>
					<?php endif; ?>
					<header class="afm__toolbar">
						<div class="afm__breadcrumbs">
							<span class="aap__title" data-apfm-title><?php \esc_html_e('Documents', 'anchor-schema'); ?></span>
							<div class="afm__breadcrumbsTrail" data-afm-breadcrumbs></div>
						</div>
						<label class="afm__search" data-apfm-search hidden>
							<span class="dashicons dashicons-search" aria-hidden="true"></span>
							<input type="search" placeholder="<?php \esc_attr_e('Search all documents…', 'anchor-schema'); ?>" data-afm-search>
						</label>
					</header>

					<section class="afm__content">
						<div class="afm__panel is-active" data-apfm-panel="files" data-afm-panel="files">
							<div class="afm__dropzone" data-afm-dropzone>
								<div class="afm__dropzoneInner">
									<div class="afm__dropIcon dashicons dashicons-cloud-upload" aria-hidden="true"></div>
									<div class="afm__dropTitle"><?php \esc_html_e('Drop files to upload', 'anchor-schema'); ?></div>
									<div class="afm__dropHint"><?php \esc_html_e('Or use the Upload button', 'anchor-schema'); ?></div>
								</div>
							</div>
							<div class="afm__grid" data-afm-grid></div>
						</div>

						<div class="afm__panel aap__panel" data-apfm-panel="orders">
							<div class="aap__grid" data-aap-orders></div>
						</div>

						<div class="afm__panel aap__panel" data-apfm-panel="downloads">
							<div class="aap__grid" data-aap-downloads></div>
						</div>

						<?php if (\current_user_can('administrator')) : ?>
						<div class="afm__panel aap__panel" data-apfm-panel="product-docs" data-afm-panel="product-docs">
							<div class="afm__twoCol">
								<div class="afm__cardBox">
									<div class="afm__sectionTitle"><?php \esc_html_e('Product Documents', 'anchor-schema'); ?></div>
									<div class="afm__grid" data-afm-product-docs></div>
								</div>
								<div class="afm__cardBox">
									<div class="afm__sectionTitle"><?php \esc_html_e('Assign documents to products', 'anchor-schema'); ?></div>
									<div class="afm__formRow">
										<label class="afm__label"><?php \esc_html_e('Select product', 'anchor-schema'); ?></label>
										<select class="afm__select" data-afm-product-select></select>
									</div>
									<div class="afm__productDocsManage" data-afm-product-docs-manage></div>
									<button type="button" class="afm__btn afm__btn--primary" data-afm-action="save-product-docs">
										<span class="dashicons dashicons-saved" aria-hidden="true"></span>
										<?php \esc_html_e('Save product documents', 'anchor-schema'); ?>
									</button>
									<div class="afm__notice" data-afm-product-docs-notice hidden></div>
								</div>
							</div>
						</div>
						<?php endif; ?>

						<?php if (\current_user_can('administrator')) : ?>
						<div class="afm__panel aap__panel" data-apfm-panel="users" data-afm-panel="users">
							<div class="afm__users" data-afm-users>
								<div class="afm__usersBar">
									<label class="afm__search afm__usersSearch">
										<span class="dashicons dashicons-search" aria-hidden="true"></span>
										<input type="search" placeholder="<?php \esc_attr_e('Search name, email or username…', 'anchor-schema'); ?>" data-afm-users-search>
									</label>
									<select class="afm__select afm__usersRole" data-afm-users-role></select>
									<button type="button" class="afm__btn afm__btn--secondary" data-afm-action="users-roles">
										<span class="dashicons dashicons-groups" aria-hidden="true"></span>
										<?php \esc_html_e('Roles', 'anchor-schema'); ?>
									</button>
									<button type="button" class="afm__btn afm__btn--secondary" data-afm-action="users-import">
										<span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span>
										<?php \esc_html_e('Import CSV', 'anchor-schema'); ?>
									</button>
									<button type="button" class="afm__btn afm__btn--primary" data-afm-action="users-add">
										<span class="dashicons dashicons-plus" aria-hidden="true"></span>
										<?php \esc_html_e('Add person', 'anchor-schema'); ?>
									</button>
								</div>
								<div class="afm__usersTable" data-afm-users-table></div>
								<div class="afm__usersPager" data-afm-users-pager></div>
							</div>
						</div>
						<?php endif; ?>

						<div class="afm__panel aap__panel" data-apfm-panel="account">
							<form class="aap__form" data-aap-profile-form>
								<div class="aap__formRow">
									<label class="aap__label"><?php \esc_html_e('First name', 'anchor-schema'); ?></label>
									<input type="text" class="afm__input" name="first_name" value="<?php echo \esc_attr($user->first_name); ?>">
								</div>
								<div class="aap__formRow">
									<label class="aap__label"><?php \esc_html_e('Last name', 'anchor-schema'); ?></label>
									<input type="text" class="afm__input" name="last_name" value="<?php echo \esc_attr($user->last_name); ?>">
								</div>
								<div class="aap__formRow">
									<label class="aap__label"><?php \esc_html_e('Email', 'anchor-schema'); ?></label>
									<input type="email" class="afm__input" name="user_email" value="<?php echo \esc_attr($user->user_email); ?>">
								</div>
								<button type="submit" class="afm__btn afm__btn--primary">
									<span class="dashicons dashicons-saved" aria-hidden="true"></span>
									<?php \esc_html_e('Save changes', 'anchor-schema'); ?>
								</button>
								<div class="aap__notice" data-aap-profile-notice hidden></div>
							</form>
						</div>

						<div class="afm__panel aap__panel" data-apfm-panel="security">
							<div class="aap__stack">
								<form class="aap__form" data-aap-password-form>
									<div class="aap__formRow">
										<label class="aap__label"><?php \esc_html_e('New password', 'anchor-schema'); ?></label>
										<input type="password" class="afm__input" name="new_password" autocomplete="new-password">
									</div>
									<button type="submit" class="afm__btn afm__btn--primary">
										<span class="dashicons dashicons-lock" aria-hidden="true"></span>
										<?php \esc_html_e('Change password', 'anchor-schema'); ?>
									</button>
									<div class="aap__notice" data-aap-password-notice hidden></div>
								</form>

								<div class="aap__divider"></div>

								<div class="aap__reset">
									<div class="aap__help">
										<?php \esc_html_e('Send a password reset link to your email.', 'anchor-schema'); ?>
									</div>
									<button type="button" class="afm__btn afm__btn--secondary" data-aap-action="send-reset">
										<span class="dashicons dashicons-email" aria-hidden="true"></span>
										<?php \esc_html_e('Email reset link', 'anchor-schema'); ?>
									</button>
									<div class="aap__notice" data-aap-reset-notice hidden></div>
								</div>
							</div>
						</div>
					</section>

					<aside class="afm__drawer" data-afm-drawer aria-label="<?php \esc_attr_e('Details', 'anchor-schema'); ?>">
						<div class="afm__drawerHeader">
							<div class="afm__drawerTitle" data-afm-drawer-title><?php \esc_html_e('Select a file', 'anchor-schema'); ?></div>
							<button type="button" class="afm__iconBtn" data-afm-action="close-drawer" aria-label="<?php \esc_attr_e('Close', 'anchor-schema'); ?>">
								<span class="dashicons dashicons-no" aria-hidden="true"></span>
							</button>
						</div>
						<div class="afm__drawerBody">
							<div class="afm__preview" data-afm-preview></div>
							<div class="afm__meta" data-afm-meta></div>
							<div class="afm__drawerActions" data-afm-drawer-actions></div>
						</div>
					</aside>

					<aside class="afm__drawer" data-aap-drawer aria-label="<?php \esc_attr_e('Order details', 'anchor-schema'); ?>">
						<div class="afm__drawerHeader">
							<div class="afm__drawerTitle" data-aap-drawer-title><?php \esc_html_e('Order', 'anchor-schema'); ?></div>
							<button type="button" class="afm__iconBtn" data-aap-action="close-drawer" aria-label="<?php \esc_attr_e('Close', 'anchor-schema'); ?>">
								<span class="dashicons dashicons-no" aria-hidden="true"></span>
							</button>
						</div>
						<div class="afm__drawerBody">
							<div class="afm__meta" data-aap-order-meta></div>
							<div class="aap__items" data-aap-order-items></div>
						</div>
					</aside>
				</main>
			</div>

			<div class="afm__modal" data-afm-modal hidden>
				<div class="afm__modalBackdrop" data-afm-action="close-modal"></div>
				<div class="afm__modalPanel" role="dialog" aria-modal="true" aria-label="<?php \esc_attr_e('Share', 'anchor-schema'); ?>">
					<div class="afm__modalHeader">
						<div class="afm__modalTitle"><?php \esc_html_e('Permissions', 'anchor-schema'); ?></div>
						<button type="button" class="afm__iconBtn" data-afm-action="close-modal" aria-label="<?php \esc_attr_e('Close', 'anchor-schema'); ?>">
							<span class="dashicons dashicons-no" aria-hidden="true"></span>
						</button>
					</div>
					<div class="afm__modalBody" data-afm-modal-body></div>
					<div class="afm__modalFooter">
						<button type="button" class="afm__btn afm__btn--ghost" data-afm-action="close-modal"><?php \esc_html_e('Cancel', 'anchor-schema'); ?></button>
						<button type="button" class="afm__btn afm__btn--primary" data-afm-action="modal-primary"><?php \esc_html_e('Save', 'anchor-schema'); ?></button>
					</div>
				</div>
			</div>
		</div>
		<?php
		return \ob_get_clean();
	}

	private static function should_enqueue_assets() {
		if (\is_admin()) return false;
		if (!\is_singular()) return false;
		$post = \get_post();
		if (!$post) return false;
		$content = (string) $post->post_content;
		return \has_shortcode($content, 'anchor_file_manager')
			|| \has_shortcode($content, 'anchor_account_portal')
			|| \has_shortcode($content, 'anchor_documents_portal');
	}
}
