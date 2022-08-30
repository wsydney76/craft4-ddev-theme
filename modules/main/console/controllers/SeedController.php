<?php

namespace modules\main\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\FileHelper;
use Exception;
use Faker\Factory;
use Faker\Generator;
use GuzzleHttp\Exception\GuzzleException;
use yii\console\ExitCode;
use yii\helpers\Console;
use function implode;
use function is_dir;
use const DIRECTORY_SEPARATOR;
use const PHP_EOL;

class SeedController extends Controller
{

	public $defaultAction = 'seed-content';

// Constants
	/**
	 * @var string
	 */
	public const CATEGORY_SECTIONHANDLE = 'topic';

	/**
	 * @var int
	 */
	public const NUM_ENTRIES = 30;

	/**
	 * @var string
	 */
	public const SECTIONHANDLE = 'article';

	public string $categorySlug = 'beispiele';

	public string $volume = 'images';

	public function actionDeleteFakedEntries(): int
	{
		$category = Entry::find()->section(self::CATEGORY_SECTIONHANDLE)->slug($this->categorySlug)->one();
		if (!$category) {
			$this->stderr('No example category found');
			return ExitCode::UNSPECIFIED_ERROR;
		}

		$entries = Entry::find()->section(self::SECTIONHANDLE)->relatedTo($category)->anyStatus()->all();
		if ($entries === []) {
			$this->stderr('No example posts found');
			return ExitCode::UNSPECIFIED_ERROR;
		}

		$count = count($entries);
		if (!$this->confirm("Delete {$count} posts related to category {$category->title}?")) {
			return ExitCode::UNSPECIFIED_ERROR;
		}

		foreach ($entries as $entry) {
			$this->stdout("Deleting {$entry->title}" . PHP_EOL);
			Craft::$app->elements->deleteElement($entry);
		}

		if (!$this->confirm("Delete example category?")) {
			return ExitCode::UNSPECIFIED_ERROR;
		}

		Craft::$app->elements->deleteElement($category);

		$this->stdout('The entries have been soft-deleted, they can be restored from the entries index.' . PHP_EOL);

		return ExitCode::OK;
	}

	public function actionSeedContent(): int
	{

		$this->actionIndexImages();

		$this->actionCreateImages();

		$this->actionCreateEntries();

		$this->actionResetHomepage();

		$this->actionResetSiteInfo();

		$this->actionCreateTransforms();

		$this->actionCreateMembersEntries();

		return ExitCode::OK;
	}

	public function actionIndexImages()
	{

        if ($this->interactive && !$this->confirm("Index images?")) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

		Craft::$app->runAction('index-assets/all');

		return ExitCode::OK;
	}

	public function actionCreateImages($num = 30, $timeout = 10): int
	{

		if ($this->interactive && !$this->confirm("Download {$num} example images from Unsplash?")) {
			return ExitCode::UNSPECIFIED_ERROR;
		}

		$this->stdout("Trying to download {$num} example images from Unsplash (Timeout {$timeout} sec.)" . PHP_EOL);

		$client = Craft::createGuzzleClient();

		$volume = Craft::$app->volumes->getVolumeByHandle('images');
		if (!$volume) {
			return ExitCode::UNSPECIFIED_ERROR;
		}

		$path = App::parseEnv($volume->fs->path) . DIRECTORY_SEPARATOR . 'examples';
		if (!is_dir($path)) {
			FileHelper::createDirectory($path);
		}

		// Don't overwrite existing images, ensure sequence number is unique
		$folders = Craft::$app->assets->findFolders(['volumeId' => $volume->id, 'path' => 'examples/']);
		if ($folders) {
			$count = Asset::find()->folderId(ArrayHelper::firstValue($folders)->id)->count();
		} else {
			$count = 0;
		}

		$start = $count + 1;
		$end = $count + $num;

		$loop = 0;

		$session = Craft::$app->assetIndexer->createIndexingSession([$volume]);

		for ($i = $start; $i <= $end; ++$i) {

			++$loop;

			$filename = "example_{$i}.jpg";

			$this->stdout("[{$loop}/{$num}] " . $filename . "...");

			$url = "https://picsum.photos/2000/1280";

			try {
				$client->get($url, ['sink' => $path . DIRECTORY_SEPARATOR . $filename, 'timeout' => $timeout]);
			} catch (Exception $exception) {
				$this->stdout(" failed: {$exception->getMessage()} \n");
				continue;
			}

			$asset = Craft::$app->assetIndexer->indexFile($volume, 'examples/' . $filename, $session->id);

			$asset->setFieldValue('copyright', 'Unsplash via picsum.photos');
			$asset->setFieldValue('altText', 'Platzhaltertext');
			Craft::$app->elements->saveElement($asset);

			$localizedAsset = $asset->getLocalized()->site('en')->one();
			if ($localizedAsset) {
				$localizedAsset->setFieldValue('altText', 'Placeholder text');
				Craft::$app->elements->saveElement($localizedAsset);
			}

			$this->stdout(" created\n");
		}

		return ExitCode::OK;
	}

	public function actionCreateEntries(int $num = self::NUM_ENTRIES, $sectionHandle = self::SECTIONHANDLE): int
	{
		$section = Craft::$app->sections->getSectionByHandle($sectionHandle);
		if (!$section) {
			$this->stderr("Invalid section {$sectionHandle}") . PHP_EOL;
			return ExitCode::UNSPECIFIED_ERROR;
		}

		if ($this->interactive && !$this->confirm("Create {$num} entries of type '{$section->name}'?")) {
			return ExitCode::UNSPECIFIED_ERROR;
		}

		$this->stdout("Creating {$num} entries of type '{$section->name}'." . PHP_EOL);

		$faker = Factory::create();

		$type = $section->getEntryTypes()[0];

		$category = $this->getCategory();

		for ($i = 1; $i <= $num; ++$i) {

			$user = User::find()->orderBy('rand()')->one();

			$entry = new Entry();
			$entry->sectionId = $section->id;
			$entry->typeId = $type->id;
			$entry->authorId = $user->id;
			$entry->postDate = $faker->dateTimeInInterval('-14 days', '-3 months');

			$title = $faker->text(50);
			$this->stdout("[{$i}/{$num}] Creating {$title} ... ");

			$entry->title = $title;
			$entry->setFieldValue('teaser', $faker->text(50));

			$image = $this->getRandomImage(900);
			if ($image) {
				$entry->setFieldValue('featuredImage', [$image->id]);
			}

			if ($category && $sectionHandle == self::SECTIONHANDLE) {
				$entry->setFieldValue('topics', [$category->id]);
			}

			$entry->setFieldValue('bodyContent', $this->getBodyContent($faker));

			if (Craft::$app->elements->saveElement($entry)) {
				$this->stdout('created, ID:' . $entry->getId() . PHP_EOL);
			} else {
				$this->stderr('failed: ' . implode(', ', $entry->getErrorSummary(true)) . PHP_EOL, Console::FG_RED);
			}
		}

		return ExitCode::OK;
	}

	protected function getCategory()
	{
		$entry = Entry::find()->section(self::CATEGORY_SECTIONHANDLE)->slug($this->categorySlug)->one();
		if (!$entry) {
			$section = Craft::$app->sections->getSectionByHandle(self::CATEGORY_SECTIONHANDLE);
			if (!$section) {
				return $entry;
			}

			$type = $section->getEntryTypes()[0];
			$user = User::find()->admin()->one();
			$entry = new Entry();
			$entry->sectionId = $section->id;
			$entry->typeId = $type->id;
			$entry->authorId = $user->id;
			$entry->title = 'Beispiele';
			$entry->slug = $this->categorySlug;
			$entry->setFieldValue('teaser', 'Sammlung von automatisch generierten Beispielen');
			$image = Asset::find()->kind('image')->width('> 1500')->orderBy('rand()')->one();
			if ($image) {
				$entry->setFieldValue('featuredImage', [$image->id]);
			}

			$this->stdout('Creating Example Content Category ... ');

			if (!Craft::$app->elements->saveElement($entry)) {
				$this->stderr('failed: ' . implode(', ', $entry->getErrorSummary(true)) . PHP_EOL, Console::FG_RED);
			} else {
				$localEntry = $entry->getLocalized()->one();
				if ($localEntry) {
					$localEntry->title = 'Examples';
					$localEntry->slug = 'examples';
					$localEntry->setFieldValue('teaser', 'Collection of automatically generated examples');
					Craft::$app->elements->saveElement($localEntry);
				}
				$this->stdout('created' . PHP_EOL);
			}
		}

		return $entry;
	}

	protected function getRandomImage($width = 1900)
	{
		return Asset::find()
			->volume($this->volume)
			->kind('image')
			->width('> ' . $width)
			->orderBy(Craft::$app->db->driverName == 'mysql' ? 'RAND()' : 'RANDOM()')
			->one();
	}

	/**
	 * @return array<string, array<array<string, string|array<string, string>|array<string, mixed[]|string|null>|array<string, mixed[]>>|string>>
	 */
	protected function getBodyContent(Generator $faker): array
	{

		$content = [
			'sortOrder' => [],
			'blocks' => []
		];

		$layouts = [
			['text', 'heading', 'image', 'text', 'image'],
			['image', 'text', 'heading', 'text', 'quote', 'text', 'gallery'],
			['text', 'text', 'text', 'heading', 'text', 'text', 'text', 'heading', 'text', 'text', 'text'],
			['image', 'image', 'image'],
			['text', 'text', 'quote', 'text', 'image'],
			['heading', 'text', 'text', 'heading', 'text', 'quote'],
			['text', 'heading', 'gallery']
		];

		$blockTypes = $faker->randomElement($layouts);

		$i = 0;
		foreach ($blockTypes as $blockType) {

			switch ($blockType) {
				case 'text':
					$paragraphs = '';
					foreach ($faker->paragraphs($faker->numberBetween(1, 5)) as $paragraph) {
						$paragraphs .= '<p>' . $paragraph . '</p>';
					}

					$block = [
						'type' => 'text',
						'fields' => [
							'text' => $paragraphs
						]
					];
					break;
				case 'heading':
					$block = [
						'type' => 'heading',
						'fields' => [
							'heading' => $faker->text(40)
						]
					];
					break;
				case 'image':
					$image = $this->getRandomImage(900);
					$block = [
						'type' => 'image',
						'fields' => [
							'image' => $image ? [$image->id] : null,
							'caption' => $faker->text(30)
						]
					];
					break;
				case 'gallery':
					$imageIds = [];
					foreach (range(1, 8) as $img) {
						$image = $this->getRandomImage(500);
						if ($image) {
							$imageIds[] = $image->id;
						}
					}

					$block = [
						'type' => 'gallery',
						'fields' => [
							'images' => $imageIds
						]
					];
					break;
				case 'quote':
					$block = [
						'type' => 'quote',
						'fields' => [
							'text' => $faker->text(80),
							'attribution' => $faker->name
						]
					];
					break;
			}

			++$i;
			$id = "new{$i}";
			$content['sortOrder'][] = $id;
			$content['blocks'][$id] = $block;
		}

		$id = 'newExample';
		$content['sortOrder'][] = $id;
		$content['blocks'][$id] = [
			'type' => 'text',
			'fields' => [
				'text' => 'Dies ist ein automatisch generierter Beispieleintrag.'
			]
		];

		return $content;
	}

	// php craft main/seed/create-images

	public function actionResetHomepage(): int
	{

		if ($this->interactive && !$this->confirm('Reset homepage content to random articles?')) {
			return ExitCode::UNSPECIFIED_ERROR;
		}

		$this->stdout("Creating content for homepage" . PHP_EOL);

		$faker = Factory::create();

		$entry = Entry::find()->section('homepage')->one();
		if (!$entry) {
			$this->stdout("Homepage not found\n");
			return ExitCode::UNSPECIFIED_ERROR;
		}

		$title = $this->prompt('New title: ', ['default' => $entry->title, 'required' => true]);

		if ($entry->title != $title) {
			$entry->title = $title;
		}

		$entry->setFieldValue('bodyContent', [
			'sortOrder' => ['new1', 'new2', 'new3'],
			'blocks' => [
				'new1' => [
					'type' => 'articles',
					'fields' => [
						'width' => 'xl',
						'style' => 'featured',
						'articles' => [],
						'articlesLimit' => 1
					]
				],
				'new2' => [
					'type' => 'articles',
					'fields' => [
						'width' => 'default',
						'style' => 'default',
						'articles' => $this->getArticleIds(4),
						'topic' => Entry::find()->section('topic')->orderBy('rand()')->limit(1)->ids()
					]
				],
				'new3' => [
					'type' => 'articles',
					'fields' => [
						'heading' => $faker->text(60),
						'width' => 'xl',
						'style' => 'boxed',
						'articles' => $this->getArticleIds(3)
					]
				]
			]
		]);

		if (!Craft::$app->elements->saveElement($entry)) {
			$this->stdout("Could not save homepage\n");
		}

		$this->stdout("Done\n");
		return ExitCode::UNSPECIFIED_ERROR;
	}

	// php craft main/seed/reset-homepage

	/**
	 * @return mixed[]
	 */
	protected function getArticleIds($num = 3): array
	{
		$faker = Factory::create();

		$entries = Entry::find()
			->section('article')
			->featuredImage(':notempty:')
			->limit($num)
			->orderBy('rand()')
			->all();

		foreach ($entries as $entry) {
			if (!$entry->intro) {
				$entry->setFieldValue('intro', $faker->text(250));
				Craft::$app->elements->saveElement($entry);
				$this->stdout("Added intro text to {$entry->title}\n");
			}
		}

		return ArrayHelper::getColumn($entries, 'id');
	}

	public function actionResetSiteInfo(): int
	{

		if ($this->interactive && !$this->confirm('Update Site Info?')) {
			return ExitCode::UNSPECIFIED_ERROR;
		}

		$this->stdout("Updating Site Info" . PHP_EOL);

		$global = GlobalSet::find()->handle('siteInfo')->one();
		if (!$global) {
			$this->stdout("Global not found\n");
			return ExitCode::UNSPECIFIED_ERROR;
		}

		if ($this->interactive) {
			$siteName = $this->prompt('Site Name: ', ['default' => $global->siteName]);
			$copyright = $this->prompt('Copyright: ', ['default' => $global->copyright]);
			$global->setFieldValue('siteName', $siteName);
			$global->setFieldValue('copyright', $copyright);
		}

		if (!$this->interactive || $this->confirm('Set fallback image?')) {
			$image = $this->getRandomImage();
			if ($image) {
				$global->setFieldValue('featuredImage', [$image->id]);
			}
		}

		if (!Craft::$app->elements->saveElement($global)) {
			$this->stdout("Could not update Global Set\n");
			return ExitCode::UNSPECIFIED_ERROR;
		}

		$this->stdout("Updated\n");

		return ExitCode::OK;
	}

	/**
	 * Creates images transforms by requesting each entry
	 *
	 * php craft main/seed/create-transforms
	 *
	 * @throws GuzzleException
	 */
	public function actionCreateTransforms(): int
	{
		return Craft::$app->runAction('main/assets/create-transforms', ['interactive' => $this->interactive]);
	}

	// php craft main/seed/create-members-entries

	public function actionCreateMembersEntries(): int
	{

		$entry = Entry::find()->section('page')->type('members')->slug('members')->one();
		if ($entry) {
			$this->stdout('Membership Entries exist');
			return ExitCode::UNSPECIFIED_ERROR;
		}

		if ($this->interactive && !$this->confirm('Create Membership Entries?')) {
			return ExitCode::UNSPECIFIED_ERROR;
		}

		$section = Craft::$app->sections->getSectionByHandle('page');
		$type = ArrayHelper::firstWhere($section->getEntryTypes(), 'handle', 'members');
		$user = User::find()->admin()->one();

		$entry = new Entry([
			'sectionId' => $section->id,
			'typeId' => $type->id,
			'authorId' => $user->id,
			'title' => 'Mitglieder',
			'slug' => 'mitglieder'
		]);
		$entry->setFieldValue('membersTemplate', 'members.twig');

		if (Craft::$app->elements->saveElement($entry)) {
			$this->stdout('Members created, ID:' . $entry->getId() . PHP_EOL);
			$this->localize($entry, 'Members', 'members');
		} else {
			$this->stderr('failed: ' . implode(', ', $entry->getErrorSummary(true)) . PHP_EOL, Console::FG_RED);
			return ExitCode::UNSPECIFIED_ERROR;
		}

		$parent = $entry;
		$items = [
			[
				'title' => 'Login',
				'slug' => 'login',
				'title_en' => 'Login',
				'slug_en' => 'login',
				'membersTemplate' => 'login.twig'
			], [
				'title' => 'Registrieren',
				'slug' => 'registrieren',
				'title_en' => 'Register',
				'slug_en' => 'register',
				'membersTemplate' => 'register.twig'
			], [
				'title' => 'Profil',
				'slug' => 'profil',
				'title_en' => 'Profile',
				'slug_en' => 'profile',
				'membersTemplate' => 'profile.twig'
			], [
				'title' => 'Passwort vergessen?',
				'slug' => 'passwort-vergessen',
				'title_en' => 'Forgot password?',
				'slug_en' => 'forgot-password',
				'membersTemplate' => 'forgotpassword.twig'
			], [
				'title' => 'Passwort vergeben',
				'slug' => 'passwort-vergeben',
				'title_en' => 'Set password',
				'slug_en' => 'set-password',
				'membersTemplate' => 'setpassword.twig'
			], [
				'title' => 'Ungültig',
				'slug' => 'ungueltig',
				'title_en' => 'Invalid',
				'slug_en' => 'invalid',
				'membersTemplate' => 'invalidtoken.twig'
			],
		];

		foreach ($items as $item) {

			$entry = new Entry([
				'sectionId' => $section->id,
				'typeId' => $type->id,
				'authorId' => $user->id,
				'title' => $item['title'],
				'slug' => $item['slug']
			]);

			$entry->setParentId($parent->getId());
			$entry->setFieldValue('membersTemplate', $item['membersTemplate']);

			if (Craft::$app->elements->saveElement($entry)) {
				$this->stdout($item['title'] . ' created, ID:' . $entry->getId() . PHP_EOL);
				$this->localize($entry, $item['title_en'], $item['slug_en']);
			} else {
				$this->stderr($item['title'] . ' failed: ' . implode(', ', $entry->getErrorSummary(true)) . PHP_EOL, Console::FG_RED);
				return ExitCode::UNSPECIFIED_ERROR;
			}
		}

		return ExitCode::OK;
	}

	protected function localize($entry, $title, $slug)
	{
		$localizedEntry = $entry->getLocalized()->site('en')->one();
		if ($localizedEntry) {
			$localizedEntry->title = $title;
			$localizedEntry->slug = $slug;
			Craft::$app->elements->saveElement($localizedEntry);
		}
	}
}
