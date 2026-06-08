import apiFetch from '@wordpress/api-fetch';
import { render, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	ExternalLink,
	Notice,
	PanelBody,
	SelectControl,
	Spinner,
	TabPanel,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

import './style.scss';

const namespace = window.odPressPilot?.restNamespace || 'od-press-pilot/v1';
const currentPage = window.odPressPilot?.page || 'generate';

const profileFields = [
	[ 'business_name', __( '事業者名', 'od-press-pilot' ), 'text' ],
	[ 'service_name', __( 'サービス名', 'od-press-pilot' ), 'text' ],
	[ 'service_description', __( 'サービス概要', 'od-press-pilot' ), 'textarea' ],
	[ 'target_customer', __( 'ターゲット顧客', 'od-press-pilot' ), 'textarea' ],
	[ 'strengths', __( '強み・特徴', 'od-press-pilot' ), 'textarea' ],
	[ 'philosophy', __( '会社理念・想い', 'od-press-pilot' ), 'textarea' ],
	[ 'catch_copy', __( 'よく使うキャッチコピー', 'od-press-pilot' ), 'textarea' ],
	[ 'ng_words', __( 'NG表現', 'od-press-pilot' ), 'textarea' ],
	[ 'cta', __( 'よく使うCTA', 'od-press-pilot' ), 'textarea' ],
	[ 'sns_policy', __( 'SNS運用方針', 'od-press-pilot' ), 'textarea' ],
	[ 'hashtag_policy', __( 'ハッシュタグ方針', 'od-press-pilot' ), 'textarea' ],
];

const defaultResult = {
	title: '',
	notice: '',
	sns_summary: '',
	sns_summary_translated: '',
	meta_description: '',
	hashtags: [],
};

const translationLanguageOptions = [
	{ label: __( '英語', 'od-press-pilot' ), value: 'en' },
	{ label: __( '中国語（簡体字）', 'od-press-pilot' ), value: 'zh-hans' },
	{ label: __( '中国語（繁体字）', 'od-press-pilot' ), value: 'zh-hant' },
	{ label: __( '韓国語', 'od-press-pilot' ), value: 'ko' },
	{ label: __( '日本語', 'od-press-pilot' ), value: 'ja' },
	{ label: __( 'カスタム入力', 'od-press-pilot' ), value: 'custom' },
];

function getErrorMessage( error, fallback ) {
	return error?.message || fallback;
}

function ProfilePage() {
	const [ profile, setProfile ] = useState( null );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		apiFetch( { path: `/${ namespace }/profile` } )
			.then( setProfile )
			.catch( ( error ) =>
				setNotice( {
					status: 'error',
					message: getErrorMessage( error, __( 'プロフィールを取得できませんでした。', 'od-press-pilot' ) ),
				} )
			);
	}, [] );

	const updateProfile = ( key, value ) => setProfile( { ...profile, [ key ]: value } );

	const saveProfile = async () => {
		setIsSaving( true );
		setNotice( null );

		try {
			const response = await apiFetch( {
				path: `/${ namespace }/profile`,
				method: 'POST',
				data: profile,
			} );
			setProfile( response );
			setNotice( { status: 'success', message: __( '広報プロフィールを保存しました。', 'od-press-pilot' ) } );
		} catch ( error ) {
			setNotice( { status: 'error', message: getErrorMessage( error, __( '保存に失敗しました。', 'od-press-pilot' ) ) } );
		} finally {
			setIsSaving( false );
		}
	};

	if ( ! profile ) {
		return <Spinner />;
	}

	return (
		<div className="od-press-pilot">
			<header className="od-press-pilot__header">
				<h1>{ __( '広報プロフィール', 'od-press-pilot' ) }</h1>
			</header>
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }
			<Card>
				<CardBody>
					<TabPanel
						className="od-press-pilot__tabs"
						tabs={ [
							{ name: 'basic', title: __( '基本プロフィール', 'od-press-pilot' ) },
							{ name: 'notes', title: __( '追加指示', 'od-press-pilot' ) },
						] }
					>
						{ ( tab ) =>
							tab.name === 'basic' ? (
								<div className="od-press-pilot__grid">
									{ profileFields.map( ( [ key, label, type ] ) =>
										type === 'textarea' ? (
											<TextareaControl
												key={ key }
												label={ label }
												value={ profile[ key ] || '' }
												onChange={ ( value ) => updateProfile( key, value ) }
											/>
										) : (
											<TextControl
												key={ key }
												label={ label }
												value={ profile[ key ] || '' }
												onChange={ ( value ) => updateProfile( key, value ) }
											/>
										)
									) }
									<SelectControl
										label={ __( '文章トーン', 'od-press-pilot' ) }
										value={ profile.tone || '丁寧' }
										options={ [
											{ label: '丁寧', value: '丁寧' },
											{ label: 'カジュアル', value: 'カジュアル' },
											{ label: '親しみやすい', value: '親しみやすい' },
											{ label: 'フォーマル', value: 'フォーマル' },
										] }
										onChange={ ( value ) => updateProfile( 'tone', value ) }
									/>
								</div>
							) : (
								<TextareaControl
									label={ __( '追加指示', 'od-press-pilot' ) }
									value={ profile.additional_notes || '' }
									rows={ 12 }
									onChange={ ( value ) => updateProfile( 'additional_notes', value ) }
								/>
							)
						}
					</TabPanel>
					<div className="od-press-pilot__actions">
						<Button variant="primary" onClick={ saveProfile } isBusy={ isSaving } disabled={ isSaving }>
							{ __( '保存', 'od-press-pilot' ) }
						</Button>
					</div>
				</CardBody>
			</Card>
		</div>
	);
}

function GeneratePage() {
	const [ providers, setProviders ] = useState( { available: false, providers: [] } );
	const [ form, setForm ] = useState( {
		post_content: '',
		audience: '',
		desired_length: '',
		translation_languages: [],
		custom_translation_language: '',
		use_emoji: false,
		generate_hashtags: true,
		provider: 'auto',
	} );
	const [ result, setResult ] = useState( null );
	const [ isGenerating, setIsGenerating ] = useState( false );
	const [ isDrafting, setIsDrafting ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		apiFetch( { path: `/${ namespace }/providers` } )
			.then( setProviders )
			.catch( () =>
				setProviders( {
					available: false,
					providers: [ { id: 'auto', label: __( 'AI Client 自動選択', 'od-press-pilot' ) } ],
				} )
			);
	}, [] );

	const providerOptions = useMemo(
		() =>
			providers.providers.length
				? providers.providers.map( ( provider ) => ( { label: provider.label, value: provider.id } ) )
				: [ { label: __( 'AI Client 自動選択', 'od-press-pilot' ), value: 'auto' } ],
		[ providers.providers ]
	);

	const updateForm = ( key, value ) => setForm( { ...form, [ key ]: value } );
	const updateResult = ( key, value ) => setResult( { ...result, [ key ]: value } );
	const updateTranslationLanguages = ( language, isChecked ) => {
		const nextLanguages = isChecked
			? Array.from( new Set( [ ...form.translation_languages, language ] ) )
			: form.translation_languages.filter( ( currentLanguage ) => currentLanguage !== language );

		updateForm( 'translation_languages', nextLanguages );
	};

	const generate = async () => {
		setIsGenerating( true );
		setNotice( null );

		try {
			const response = await apiFetch( {
				path: `/${ namespace }/generate`,
				method: 'POST',
				data: form,
			} );
			setResult( { ...defaultResult, ...response } );
		} catch ( error ) {
			setNotice( { status: 'error', message: getErrorMessage( error, __( 'コンテンツ生成に失敗しました。', 'od-press-pilot' ) ) } );
		} finally {
			setIsGenerating( false );
		}
	};

	const createDraft = async () => {
		setIsDrafting( true );
		setNotice( null );

		try {
			const response = await apiFetch( {
				path: `/${ namespace }/draft`,
				method: 'POST',
				data: result,
			} );
			setNotice( {
				status: 'success',
				message: (
					<>
						{ response.message }
						{ response.edit_url && (
							<>
								{ ' ' }
								<ExternalLink href={ response.edit_url }>{ __( '編集画面を開く', 'od-press-pilot' ) }</ExternalLink>
							</>
						) }
					</>
				),
			} );
		} catch ( error ) {
			setNotice( { status: 'error', message: getErrorMessage( error, __( '下書き作成に失敗しました。', 'od-press-pilot' ) ) } );
		} finally {
			setIsDrafting( false );
		}
	};

	const copyText = async ( value ) => {
		await window.navigator.clipboard.writeText( value );
		setNotice( { status: 'success', message: __( 'コピーしました。', 'od-press-pilot' ) } );
	};

	const hashtagsText = Array.isArray( result?.hashtags ) ? result.hashtags.join( ' ' ) : '';

	return (
		<div className="od-press-pilot">
			<header className="od-press-pilot__header">
				<h1>{ __( 'コンテンツ生成', 'od-press-pilot' ) }</h1>
			</header>
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }
			{ ! providers.available && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'AI Provider が利用できません。WordPress 7.0 以上で Settings > Connectors から AI Provider を設定してください。', 'od-press-pilot' ) }
				</Notice>
			) }
			<div className="od-press-pilot__layout">
				<Card>
					<CardBody>
						<PanelBody title={ __( '生成条件', 'od-press-pilot' ) } initialOpen>
							<div className="od-press-pilot__form-stack">
								<TextareaControl
									label={ __( '投稿内容', 'od-press-pilot' ) }
									value={ form.post_content }
									rows={ 10 }
									required
									onChange={ ( value ) => updateForm( 'post_content', value ) }
								/>
								<TextControl
									label={ __( '対象読者', 'od-press-pilot' ) }
									value={ form.audience }
									onChange={ ( value ) => updateForm( 'audience', value ) }
								/>
								<TextControl
									type="number"
									label={ __( '希望文字数', 'od-press-pilot' ) }
									value={ form.desired_length }
									onChange={ ( value ) => updateForm( 'desired_length', value ) }
								/>
								<fieldset className="od-press-pilot__translation-control">
									<legend>{ __( '翻訳言語', 'od-press-pilot' ) }</legend>
									<div className="od-press-pilot__translation-options">
										{ translationLanguageOptions.map( ( option ) => (
											<CheckboxControl
												key={ option.value }
												label={ option.label }
												checked={ form.translation_languages.includes( option.value ) }
												onChange={ ( value ) => updateTranslationLanguages( option.value, value ) }
											/>
										) ) }
									</div>
								</fieldset>
								{ form.translation_languages.includes( 'custom' ) && (
									<TextControl
										label={ __( 'カスタム翻訳言語', 'od-press-pilot' ) }
										placeholder={ __( '例: フランス語、スペイン語、ベトナム語', 'od-press-pilot' ) }
										value={ form.custom_translation_language }
										onChange={ ( value ) => updateForm( 'custom_translation_language', value ) }
									/>
								) }
								<CheckboxControl
									label={ __( '絵文字を利用する', 'od-press-pilot' ) }
									checked={ form.use_emoji }
									onChange={ ( value ) => updateForm( 'use_emoji', value ) }
								/>
								<CheckboxControl
									label={ __( 'ハッシュタグを生成する', 'od-press-pilot' ) }
									checked={ form.generate_hashtags }
									onChange={ ( value ) => updateForm( 'generate_hashtags', value ) }
								/>
								<SelectControl
									label={ __( 'AI Provider', 'od-press-pilot' ) }
									value={ form.provider }
									options={ providerOptions }
									onChange={ ( value ) => updateForm( 'provider', value ) }
								/>
							</div>
							<div className="od-press-pilot__actions">
								<Button variant="primary" onClick={ generate } isBusy={ isGenerating } disabled={ isGenerating || ! form.post_content }>
									{ __( '生成', 'od-press-pilot' ) }
								</Button>
							</div>
						</PanelBody>
					</CardBody>
				</Card>
				<ResultPanel
					result={ result }
					hashtagsText={ hashtagsText }
					updateResult={ updateResult }
					copyText={ copyText }
					generate={ generate }
					createDraft={ createDraft }
					isGenerating={ isGenerating }
					isDrafting={ isDrafting }
				/>
			</div>
		</div>
	);
}

function ResultPanel( { result, hashtagsText, updateResult, copyText, generate, createDraft, isGenerating, isDrafting } ) {
	if ( ! result ) {
		return (
			<Card>
				<CardBody>
					<p className="od-press-pilot__empty">{ __( '生成結果がここに表示されます。', 'od-press-pilot' ) }</p>
				</CardBody>
			</Card>
		);
	}

	const fields = [
		[ 'title', __( 'タイトル', 'od-press-pilot' ), result.title ],
		[ 'notice', __( '本文', 'od-press-pilot' ), result.notice ],
		[ 'sns_summary', __( 'SNS要約', 'od-press-pilot' ), result.sns_summary ],
		[ 'sns_summary_translated', __( '翻訳版SNS要約', 'od-press-pilot' ), result.sns_summary_translated ],
		[ 'meta_description', __( 'メタディスクリプション', 'od-press-pilot' ), result.meta_description ],
	];

	return (
		<Card>
			<CardBody>
				<PanelBody title={ __( '生成結果', 'od-press-pilot' ) } initialOpen>
					{ fields.map( ( [ key, label, value ] ) => (
						<div className="od-press-pilot__result-field" key={ key }>
							<TextareaControl
								label={ label }
								value={ value || '' }
								rows={ key === 'notice' ? 10 : 4 }
								onChange={ ( nextValue ) => updateResult( key, nextValue ) }
							/>
							<Button variant="secondary" onClick={ () => copyText( value || '' ) }>
								{ __( 'コピー', 'od-press-pilot' ) }
							</Button>
						</div>
					) ) }
					<div className="od-press-pilot__result-field">
						<TextareaControl
							label={ __( 'ハッシュタグ', 'od-press-pilot' ) }
							value={ hashtagsText }
							rows={ 3 }
							onChange={ ( value ) => updateResult( 'hashtags', value.split( /\s+/ ).filter( Boolean ) ) }
						/>
						<Button variant="secondary" onClick={ () => copyText( hashtagsText ) }>
							{ __( 'コピー', 'od-press-pilot' ) }
						</Button>
					</div>
					<div className="od-press-pilot__actions">
						<Button variant="secondary" onClick={ generate } isBusy={ isGenerating } disabled={ isGenerating }>
							{ __( '再生成', 'od-press-pilot' ) }
						</Button>
						<Button variant="primary" onClick={ createDraft } isBusy={ isDrafting } disabled={ isDrafting }>
							{ __( '投稿下書き作成', 'od-press-pilot' ) }
						</Button>
					</div>
				</PanelBody>
			</CardBody>
		</Card>
	);
}

render( currentPage === 'profile' ? <ProfilePage /> : <GeneratePage />, document.getElementById( 'od-press-pilot-admin' ) );
