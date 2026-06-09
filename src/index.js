import apiFetch from '@wordpress/api-fetch';
import { render, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	ExternalLink,
	Modal,
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

const profilePromptTemplate = `あなたは中小企業・店舗・地域事業者の広報プロフィールを整理する編集者です。
以下の事業情報をもとに、WordPressプラグイン「OD Press Pilot」の広報プロフィール欄へ貼り付ける情報を作成してください。

目的:
今後のお知らせ文、SNS投稿、メタディスクリプション、ハッシュタグ生成に使うため、事業者らしさが安定して反映される広報プロフィールを作る。

重要なルール:
- 入力情報にない事実は追加しないでください。
- 不明な内容は推測せず、「未記入」または「確認が必要」としてください。
- 誇大表現、断定的すぎる表現、根拠のないNo.1表現は避けてください。
- 実際のお知らせ文に使いやすい、自然で具体的な日本語にしてください。
- 各項目はそのまま管理画面へ貼り付けられる文章にしてください。
- 専門用語が必要な場合は、一般読者にも伝わる表現にしてください。
- 出力は下記の項目名ごとに分けてください。

事業情報:
【事業者名】
（ここに入力）

【サービス名】
（ここに入力）

【WebサイトURL】
（ここに入力）

【事業内容】
（ここに入力）

【主な商品・サービス】
（ここに入力）

【対象顧客】
（ここに入力）

【対応エリア】
（ここに入力）

【強み・特徴】
（ここに入力）

【理念・大切にしていること】
（ここに入力）

【よく使う表現・キャッチコピー】
（ここに入力）

【避けたい表現・言い回し】
（ここに入力）

【SNSの雰囲気】
例: 丁寧、親しみやすい、専門的、やわらかい、落ち着いた雰囲気 など

【問い合わせ・予約・購入などの導線】
（ここに入力）

作成してほしい項目:
1. 事業者名
2. サービス名
3. サービス概要
4. ターゲット顧客
5. 強み・特徴
6. 会社理念・想い
7. よく使うキャッチコピー
8. NG表現
9. よく使うCTA
10. SNS運用方針
11. ハッシュタグ方針
12. 追加指示

出力形式:
各項目を以下の形式で出力してください。

## 事業者名
...

## サービス名
...

## サービス概要
...

## ターゲット顧客
...

## 強み・特徴
...

## 会社理念・想い
...

## よく使うキャッチコピー
...

## NG表現
...

## よく使うCTA
...

## SNS運用方針
...

## ハッシュタグ方針
...

## 追加指示
...`;

const defaultResult = {
	title: '',
	notice: '',
	x_text: '',
	translated_x_texts: [],
	meta_description: '',
	hashtags: [],
};

const defaultGenerationForm = {
	post_content: '',
	audience: '',
	desired_length: '',
	translation_languages: [],
	custom_translation_language: '',
	use_emoji: false,
	generate_hashtags: true,
	provider: 'auto',
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

function normalizeResult( response ) {
	return {
		...defaultResult,
		...response,
		x_text: response?.x_text || response?.sns_summary || '',
		translated_x_texts: Array.isArray( response?.translated_x_texts ) ? response.translated_x_texts : [],
	};
}

function normalizeGenerationForm( input = {}, providerOptions = [] ) {
	const providerValues = providerOptions.map( ( option ) => option.value );
	const provider = input.provider || defaultGenerationForm.provider;

	return {
		...defaultGenerationForm,
		post_content: input.post_content || '',
		audience: input.audience || '',
		desired_length: input.desired_length ? String( input.desired_length ) : '',
		translation_languages: Array.isArray( input.translation_languages ) ? input.translation_languages : [],
		custom_translation_language: input.custom_translation_language || '',
		use_emoji: Boolean( input.use_emoji ),
		generate_hashtags: input.generate_hashtags !== undefined ? Boolean( input.generate_hashtags ) : defaultGenerationForm.generate_hashtags,
		provider: providerValues.length && ! providerValues.includes( provider ) ? 'auto' : provider,
	};
}

function GenerationFields( { form, onChange, providerOptions, includeName = false } ) {
	const updateTranslationLanguages = ( language, isChecked ) => {
		const nextLanguages = isChecked
			? Array.from( new Set( [ ...form.translation_languages, language ] ) )
			: form.translation_languages.filter( ( currentLanguage ) => currentLanguage !== language );

		onChange( 'translation_languages', nextLanguages );
	};

	return (
		<div className="od-press-pilot__form-stack">
			{ includeName && (
				<TextControl
					label={ __( 'テンプレート名', 'od-press-pilot' ) }
					value={ form.name || '' }
					required
					onChange={ ( value ) => onChange( 'name', value ) }
				/>
			) }
			<TextareaControl
				label={ __( '投稿内容', 'od-press-pilot' ) }
				value={ form.post_content }
				rows={ 10 }
				required
				onChange={ ( value ) => onChange( 'post_content', value ) }
			/>
			<TextareaControl
				label={ __( '対象読者', 'od-press-pilot' ) }
				value={ form.audience }
				rows={ 4 }
				onChange={ ( value ) => onChange( 'audience', value ) }
			/>
			<TextControl
				type="number"
				label={ __( '希望文字数', 'od-press-pilot' ) }
				value={ form.desired_length }
				onChange={ ( value ) => onChange( 'desired_length', value ) }
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
					onChange={ ( value ) => onChange( 'custom_translation_language', value ) }
				/>
			) }
			<CheckboxControl
				label={ __( '絵文字を利用する', 'od-press-pilot' ) }
				checked={ form.use_emoji }
				onChange={ ( value ) => onChange( 'use_emoji', value ) }
			/>
			<CheckboxControl
				label={ __( 'ハッシュタグを生成する', 'od-press-pilot' ) }
				checked={ form.generate_hashtags }
				onChange={ ( value ) => onChange( 'generate_hashtags', value ) }
			/>
			<SelectControl
				label={ __( 'AI Provider', 'od-press-pilot' ) }
				value={ form.provider }
				options={ providerOptions }
				onChange={ ( value ) => onChange( 'provider', value ) }
			/>
		</div>
	);
}

function ProfilePage() {
	const [ profile, setProfile ] = useState( null );
	const [ activeProfileTab, setActiveProfileTab ] = useState( 'basic' );
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

	const copyPromptTemplate = async () => {
		await window.navigator.clipboard.writeText( profilePromptTemplate );
		setNotice( { status: 'success', message: __( 'プロンプトテンプレートをコピーしました。', 'od-press-pilot' ) } );
	};

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

	const renderProfileTab = ( tab ) => {
		if ( tab.name === 'basic' ) {
			return (
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
			);
		}

		if ( tab.name === 'notes' ) {
			return (
				<TextareaControl
					label={ __( '追加指示', 'od-press-pilot' ) }
					value={ profile.additional_notes || '' }
					rows={ 12 }
					onChange={ ( value ) => updateProfile( 'additional_notes', value ) }
				/>
			);
		}

		return (
			<div className="od-press-pilot__template">
				<p className="od-press-pilot__template-description">
					{ __(
						'このテンプレートをコピーして AI に貼り付け、事業情報を入力すると、広報プロフィールへ転記しやすい文章を作成できます。',
						'od-press-pilot'
					) }
				</p>
				<TextareaControl
					label={ __( 'プロンプトテンプレート', 'od-press-pilot' ) }
					value={ profilePromptTemplate }
					rows={ 28 }
					readOnly
				/>
				<div className="od-press-pilot__actions">
					<Button variant="secondary" onClick={ copyPromptTemplate }>
						{ __( 'コピー', 'od-press-pilot' ) }
					</Button>
				</div>
			</div>
		);
	};

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
						onSelect={ setActiveProfileTab }
						tabs={ [
							{ name: 'basic', title: __( '基本プロフィール', 'od-press-pilot' ) },
							{ name: 'notes', title: __( '追加指示', 'od-press-pilot' ) },
							{ name: 'prompt-template', title: __( 'プロンプトテンプレート', 'od-press-pilot' ) },
						] }
					>
						{ renderProfileTab }
					</TabPanel>
					{ activeProfileTab !== 'prompt-template' && (
						<div className="od-press-pilot__actions">
							<Button variant="primary" onClick={ saveProfile } isBusy={ isSaving } disabled={ isSaving }>
								{ __( '保存', 'od-press-pilot' ) }
							</Button>
						</div>
					) }
				</CardBody>
			</Card>
		</div>
	);
}

function GeneratePage() {
	const [ providers, setProviders ] = useState( { available: false, providers: [] } );
	const [ templates, setTemplates ] = useState( [] );
	const [ selectedTemplateId, setSelectedTemplateId ] = useState( '' );
	const [ form, setForm ] = useState( defaultGenerationForm );
	const [ result, setResult ] = useState( null );
	const [ isGenerating, setIsGenerating ] = useState( false );
	const [ isDrafting, setIsDrafting ] = useState( false );
	const [ isSavingTemplate, setIsSavingTemplate ] = useState( false );
	const [ isSaveTemplateOpen, setIsSaveTemplateOpen ] = useState( false );
	const [ saveTemplateName, setSaveTemplateName ] = useState( '' );
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

	useEffect( () => {
		apiFetch( { path: `/${ namespace }/templates` } )
			.then( ( response ) => setTemplates( Array.isArray( response ) ? response : [] ) )
			.catch( () => setTemplates( [] ) );
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
	const templateOptions = [
		{ label: __( 'テンプレートを選択', 'od-press-pilot' ), value: '' },
		...templates.map( ( template ) => ( { label: template.name, value: template.id } ) ),
	];

	const applyTemplate = () => {
		const template = templates.find( ( currentTemplate ) => currentTemplate.id === selectedTemplateId );

		if ( ! template ) {
			return;
		}

		setForm( normalizeGenerationForm( template, providerOptions ) );
		setNotice( { status: 'success', message: __( 'テンプレートを生成条件に反映しました。', 'od-press-pilot' ) } );
	};

	const saveCurrentTemplate = async () => {
		if ( ! saveTemplateName.trim() ) {
			setNotice( { status: 'error', message: __( 'テンプレート名を入力してください。', 'od-press-pilot' ) } );
			return;
		}

		setIsSavingTemplate( true );
		setNotice( null );

		try {
			const response = await apiFetch( {
				path: `/${ namespace }/templates`,
				method: 'POST',
				data: {
					...form,
					name: saveTemplateName,
				},
			} );
			setTemplates( [ ...templates, response ] );
			setSelectedTemplateId( response.id );
			setSaveTemplateName( '' );
			setIsSaveTemplateOpen( false );
			setNotice( { status: 'success', message: __( 'テンプレートを保存しました。', 'od-press-pilot' ) } );
		} catch ( error ) {
			setNotice( { status: 'error', message: getErrorMessage( error, __( 'テンプレートの保存に失敗しました。', 'od-press-pilot' ) ) } );
		} finally {
			setIsSavingTemplate( false );
		}
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
			setResult( normalizeResult( response ) );
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
							<div className="od-press-pilot__template-picker">
								<SelectControl
									label={ __( '保存済みテンプレート', 'od-press-pilot' ) }
									value={ selectedTemplateId }
									options={ templateOptions }
									onChange={ setSelectedTemplateId }
								/>
								<Button variant="secondary" onClick={ applyTemplate } disabled={ ! selectedTemplateId }>
									{ __( '適用', 'od-press-pilot' ) }
								</Button>
							</div>
							<GenerationFields form={ form } onChange={ updateForm } providerOptions={ providerOptions } />
							<div className="od-press-pilot__actions">
								<Button variant="primary" onClick={ generate } isBusy={ isGenerating } disabled={ isGenerating || ! form.post_content }>
									{ __( '生成', 'od-press-pilot' ) }
								</Button>
								<Button variant="secondary" onClick={ () => setIsSaveTemplateOpen( true ) }>
									{ __( 'テンプレートに保存する', 'od-press-pilot' ) }
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
			{ isSaveTemplateOpen && (
				<Modal
					title={ __( 'テンプレートに保存', 'od-press-pilot' ) }
					onRequestClose={ () => setIsSaveTemplateOpen( false ) }
				>
					<TextControl
						label={ __( 'テンプレート名', 'od-press-pilot' ) }
						value={ saveTemplateName }
						required
						onChange={ setSaveTemplateName }
					/>
					<div className="od-press-pilot__actions">
						<Button variant="primary" onClick={ saveCurrentTemplate } isBusy={ isSavingTemplate } disabled={ isSavingTemplate || ! saveTemplateName.trim() }>
							{ __( '保存', 'od-press-pilot' ) }
						</Button>
						<Button variant="secondary" onClick={ () => setIsSaveTemplateOpen( false ) } disabled={ isSavingTemplate }>
							{ __( 'キャンセル', 'od-press-pilot' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
}

function TemplatesPage() {
	const [ providers, setProviders ] = useState( { available: false, providers: [] } );
	const [ templates, setTemplates ] = useState( [] );
	const [ selectedTemplateId, setSelectedTemplateId ] = useState( '' );
	const [ draft, setDraft ] = useState( { ...defaultGenerationForm, name: '' } );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ isDeleting, setIsDeleting ] = useState( false );
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

		apiFetch( { path: `/${ namespace }/templates` } )
			.then( ( response ) => setTemplates( Array.isArray( response ) ? response : [] ) )
			.catch( ( error ) =>
				setNotice( {
					status: 'error',
					message: getErrorMessage( error, __( 'テンプレートを取得できませんでした。', 'od-press-pilot' ) ),
				} )
			)
			.finally( () => setIsLoading( false ) );
	}, [] );

	const providerOptions = useMemo(
		() =>
			providers.providers.length
				? providers.providers.map( ( provider ) => ( { label: provider.label, value: provider.id } ) )
				: [ { label: __( 'AI Client 自動選択', 'od-press-pilot' ), value: 'auto' } ],
		[ providers.providers ]
	);

	const selectTemplate = ( template ) => {
		setSelectedTemplateId( template.id );
		setDraft( {
			...normalizeGenerationForm( template, providerOptions ),
			id: template.id,
			name: template.name || '',
		} );
		setNotice( null );
	};

	const createNewTemplate = () => {
		setSelectedTemplateId( '' );
		setDraft( { ...defaultGenerationForm, name: '' } );
		setNotice( null );
	};

	const updateDraft = ( key, value ) => setDraft( { ...draft, [ key ]: value } );

	const saveTemplate = async () => {
		if ( ! draft.name.trim() ) {
			setNotice( { status: 'error', message: __( 'テンプレート名を入力してください。', 'od-press-pilot' ) } );
			return;
		}

		setIsSaving( true );
		setNotice( null );

		try {
			const response = await apiFetch( {
				path: selectedTemplateId ? `/${ namespace }/templates/${ selectedTemplateId }` : `/${ namespace }/templates`,
				method: selectedTemplateId ? 'PUT' : 'POST',
				data: draft,
			} );
			const nextTemplates = selectedTemplateId
				? templates.map( ( template ) => ( template.id === response.id ? response : template ) )
				: [ ...templates, response ];

			setTemplates( nextTemplates );
			setSelectedTemplateId( response.id );
			setDraft( {
				...normalizeGenerationForm( response, providerOptions ),
				id: response.id,
				name: response.name || '',
			} );
			setNotice( { status: 'success', message: __( 'テンプレートを保存しました。', 'od-press-pilot' ) } );
		} catch ( error ) {
			setNotice( { status: 'error', message: getErrorMessage( error, __( 'テンプレートの保存に失敗しました。', 'od-press-pilot' ) ) } );
		} finally {
			setIsSaving( false );
		}
	};

	const deleteTemplate = async () => {
		if ( ! selectedTemplateId || ! window.confirm( __( 'このテンプレートを削除しますか？', 'od-press-pilot' ) ) ) {
			return;
		}

		setIsDeleting( true );
		setNotice( null );

		try {
			const response = await apiFetch( {
				path: `/${ namespace }/templates/${ selectedTemplateId }`,
				method: 'DELETE',
			} );
			setTemplates( Array.isArray( response.templates ) ? response.templates : templates.filter( ( template ) => template.id !== selectedTemplateId ) );
			createNewTemplate();
			setNotice( { status: 'success', message: __( 'テンプレートを削除しました。', 'od-press-pilot' ) } );
		} catch ( error ) {
			setNotice( { status: 'error', message: getErrorMessage( error, __( 'テンプレートの削除に失敗しました。', 'od-press-pilot' ) ) } );
		} finally {
			setIsDeleting( false );
		}
	};

	if ( isLoading ) {
		return <Spinner />;
	}

	return (
		<div className="od-press-pilot">
			<header className="od-press-pilot__header">
				<h1>{ __( 'テンプレート', 'od-press-pilot' ) }</h1>
			</header>
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }
			<div className="od-press-pilot__layout od-press-pilot__layout--templates">
				<Card>
					<CardBody>
						<div className="od-press-pilot__template-list-header">
							<h2>{ __( '保存済みテンプレート', 'od-press-pilot' ) }</h2>
							<Button variant="secondary" onClick={ createNewTemplate }>
								{ __( '新規作成', 'od-press-pilot' ) }
							</Button>
						</div>
						{ templates.length ? (
							<div className="od-press-pilot__template-list">
								{ templates.map( ( template ) => (
									<Button
										key={ template.id }
										variant={ selectedTemplateId === template.id ? 'primary' : 'tertiary' }
										onClick={ () => selectTemplate( template ) }
									>
										{ template.name }
									</Button>
								) ) }
							</div>
						) : (
							<p className="od-press-pilot__empty">{ __( '保存済みテンプレートはありません。', 'od-press-pilot' ) }</p>
						) }
					</CardBody>
				</Card>
				<Card>
					<CardBody>
						<PanelBody title={ selectedTemplateId ? __( 'テンプレート編集', 'od-press-pilot' ) : __( 'テンプレート新規作成', 'od-press-pilot' ) } initialOpen>
							<GenerationFields form={ draft } onChange={ updateDraft } providerOptions={ providerOptions } includeName />
							<div className="od-press-pilot__actions">
								<Button variant="primary" onClick={ saveTemplate } isBusy={ isSaving } disabled={ isSaving || ! draft.name.trim() }>
									{ __( '保存', 'od-press-pilot' ) }
								</Button>
								{ selectedTemplateId && (
									<Button variant="secondary" isDestructive onClick={ deleteTemplate } isBusy={ isDeleting } disabled={ isDeleting }>
										{ __( '削除', 'od-press-pilot' ) }
									</Button>
								) }
							</div>
						</PanelBody>
					</CardBody>
				</Card>
			</div>
		</div>
	);
}

function ResultPanel( { result, hashtagsText, updateResult, copyText, generate, createDraft, isGenerating, isDrafting } ) {
	if ( ! result ) {
		return (
			<Card>
				<CardBody>
					<div className="od-press-pilot__result-status" aria-live="polite" aria-busy={ isGenerating }>
						{ isGenerating && <Spinner /> }
						<p className="od-press-pilot__empty">
							{ isGenerating
								? __( '生成中です。少々お待ちください。', 'od-press-pilot' )
								: __( '生成結果がここに表示されます。', 'od-press-pilot' ) }
						</p>
					</div>
				</CardBody>
			</Card>
		);
	}

	const fields = [
		[ 'title', __( 'タイトル', 'od-press-pilot' ), result.title ],
		[ 'notice', __( '本文', 'od-press-pilot' ), result.notice ],
		[ 'x_text', __( 'X用テキスト', 'od-press-pilot' ), result.x_text ],
		[ 'meta_description', __( 'メタディスクリプション', 'od-press-pilot' ), result.meta_description ],
	];
	const translatedXTexts = Array.isArray( result.translated_x_texts ) ? result.translated_x_texts : [];
	const updateTranslatedXText = ( index, value ) => {
		const nextTexts = translatedXTexts.map( ( translatedText, currentIndex ) =>
			currentIndex === index ? { ...translatedText, text: value } : translatedText
		);

		updateResult( 'translated_x_texts', nextTexts );
	};

	return (
		<Card>
			<CardBody>
				<PanelBody title={ __( '生成結果', 'od-press-pilot' ) } initialOpen>
					{ isGenerating && (
						<div className="od-press-pilot__result-status od-press-pilot__result-status--inline" aria-live="polite" aria-busy="true">
							<Spinner />
							<p className="od-press-pilot__empty">{ __( '再生成中です。完了すると内容が更新されます。', 'od-press-pilot' ) }</p>
						</div>
					) }
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
					{ translatedXTexts.map( ( translatedText, index ) => {
						const language = translatedText.language || __( '翻訳', 'od-press-pilot' );
						const text = translatedText.text || '';

						return (
							<div className="od-press-pilot__result-field" key={ `${ language }-${ index }` }>
								<TextareaControl
									label={ sprintf(
										/* translators: %s: Translation language name. */
										__( '%s翻訳版X用テキスト', 'od-press-pilot' ),
										language
									) }
									value={ text }
									rows={ 4 }
									onChange={ ( nextValue ) => updateTranslatedXText( index, nextValue ) }
								/>
								<Button variant="secondary" onClick={ () => copyText( text ) }>
									{ __( 'コピー', 'od-press-pilot' ) }
								</Button>
							</div>
						);
					} ) }
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

function App() {
	if ( currentPage === 'profile' ) {
		return <ProfilePage />;
	}

	if ( currentPage === 'templates' ) {
		return <TemplatesPage />;
	}

	return <GeneratePage />;
}

render( <App />, document.getElementById( 'od-press-pilot-admin' ) );
