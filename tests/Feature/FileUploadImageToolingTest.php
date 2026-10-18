<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    config(['alfakit.uploads.temporary_disk' => 'local', 'alfakit.default_filesystem_disk' => 'public']);
});

function imageToolingUser(string $email = 'images@example.com'): User
{
    return User::query()->create(['name' => 'Images', 'email' => $email, 'password' => 'password']);
}

function imageToolingUpload(UploadedFile $file): string
{
    return (string) test()->post('/admin/_alfakit/uploads', ['file' => $file], ['Accept' => 'application/json'])
        ->assertOk()
        ->json('key');
}

function imageToolingActAs(User $user): void
{
    test()->actingAs($user);
    alfakit()->setCurrentPanel(alfakit()->getPanel('admin'));
}

/**
 * A JPEG with an EXIF APP1 segment holding only the Orientation tag.
 */
function jpegWithOrientation(int $width, int $height, int $orientation): string
{
    $image = imagecreatetruecolor($width, $height);
    // Top-left pixel red, so the rotation can be checked.
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 0, 0, 255));
    imagefilledrectangle($image, 0, 0, 3, 3, (int) imagecolorallocate($image, 255, 0, 0));
    ob_start();
    imagejpeg($image, null, 100);
    $jpeg = (string) ob_get_clean();

    $tiff = "MM\x00\x2A\x00\x00\x00\x08"         // big-endian TIFF header, IFD at 8
        ."\x00\x01"                                // one entry
        ."\x01\x12\x00\x03\x00\x00\x00\x01"       // Orientation, SHORT, count 1
        .pack('n', $orientation)."\x00\x00"
        ."\x00\x00\x00\x00";                       // no next IFD
    $app1 = "Exif\x00\x00".$tiff;

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
}

it('serialises the image editor, validation limits and paste support', function (): void {
    $props = schema([
        FileUpload::make('cover')
            ->image()
            ->imageEditor()
            ->imageEditorAspectRatios(['16:9', null, 'bogus'])
            ->imageAspectRatio('16:9')
            ->imageDimensions(minWidth: 100, maxHeight: fn (): int => 2000),
    ])->fill()->toSchema()[0];

    expect($props['imageEditor'])->toBe(['aspectRatios' => ['16:9', null, null], 'circle' => false])
        ->and($props['imageAspectRatio'])->toBe(['16:9'])
        ->and($props['imageDimensions'])->toBe(['minWidth' => 100, 'maxHeight' => 2000])
        ->and($props['pasteable'])->toBeTrue();
});

it('defaults the editor ratios to the accepted aspect ratios and forces 1:1 for circles', function (): void {
    $fromRatio = schema([FileUpload::make('a')->imageEditor()->imageAspectRatio(['4:3', '1:1'])])->fill()->toSchema()[0];
    $free = schema([FileUpload::make('b')->imageEditor()])->fill()->toSchema()[0];
    $circle = schema([FileUpload::make('c')->avatar()->imageEditor()->circleCropper()->imageEditorAspectRatioOptions(['16:9'])])->fill()->toSchema()[0];
    $off = schema([FileUpload::make('d')->circleCropper()->pasteable(false)])->fill()->toSchema()[0];

    expect($fromRatio['imageEditor']['aspectRatios'])->toBe(['4:3', '1:1'])
        ->and($free['imageEditor']['aspectRatios'])->toBe([null])
        ->and($circle['imageEditor'])->toBe(['aspectRatios' => ['1:1'], 'circle' => true])
        ->and($off['imageEditor'] ?? null)->toBeNull()
        ->and($off['pasteable'])->toBeFalse();
});

it('validates image dimensions against the file contents', function (): void {
    $user = imageToolingUser();
    $this->actingAs($user);
    $small = imageToolingUpload(UploadedFile::fake()->image('small.png', 50, 50));
    $large = imageToolingUpload(UploadedFile::fake()->image('large.png', 300, 200));

    imageToolingActAs($user);

    $tooSmall = schema([FileUpload::make('photo')->imageDimensions(minWidth: 100, minHeight: 100)])->fill();
    $tooSmall->rawState(['photo' => [$small]]);
    expect(fn () => $tooSmall->validate())->toThrow(ValidationException::class);

    $tooLarge = schema([FileUpload::make('photo')->imageDimensions(maxWidth: 200)])->fill();
    $tooLarge->rawState(['photo' => [$large]]);
    expect(fn () => $tooLarge->validate())->toThrow(ValidationException::class);

    $ok = schema([FileUpload::make('photo')->imageDimensions(minWidth: fn (): int => 100, maxWidth: 400)])->fill();
    $ok->rawState(['photo' => [$large]]);
    expect($ok->validate()['photo'])->toEndWith('.png');
});

it('validates the aspect ratio against the file contents', function (): void {
    $user = imageToolingUser();
    $this->actingAs($user);
    $wide = imageToolingUpload(UploadedFile::fake()->image('wide.png', 160, 90));
    $square = imageToolingUpload(UploadedFile::fake()->image('square.png', 90, 90));

    imageToolingActAs($user);

    $form = schema([FileUpload::make('photo')->imageAspectRatio('16:9')])->fill();
    $form->rawState(['photo' => [$square]]);
    expect(fn () => $form->validate())->toThrow(ValidationException::class);

    $either = schema([FileUpload::make('photo')->multiple()->imageAspectRatio(['1:1', '16/9'])])->fill();
    $either->rawState(['photo' => [$wide, $square]]);
    expect($either->validate()['photo'])->toHaveCount(2);
});

it('rejects files that are not images when dimensions are required', function (): void {
    $user = imageToolingUser();
    $this->actingAs($user);
    $pdf = imageToolingUpload(UploadedFile::fake()->create('doc.pdf', 5, 'application/pdf'));

    imageToolingActAs($user);
    $form = schema([FileUpload::make('photo')->imageDimensions(minWidth: 1)])->fill();
    $form->rawState(['photo' => [$pdf]]);

    expect(fn () => $form->validate())->toThrow(ValidationException::class);
});

it('rotates JPEGs upright from their EXIF orientation when storing', function (): void {
    if (! FileUpload::canOrientImagesOnServer()) {
        $this->markTestSkipped('GD and exif are required.');
    }

    $user = imageToolingUser();
    $this->actingAs($user);
    $key = imageToolingUpload(UploadedFile::fake()->createWithContent('photo.jpg', jpegWithOrientation(40, 20, 6)));

    imageToolingActAs($user);
    $form = schema([FileUpload::make('photo')->orientImagesFromExif()->directory('photos')])->fill();
    $form->rawState(['photo' => [$key]]);
    $path = $form->validate()['photo'];

    $size = getimagesizefromstring((string) Storage::disk('public')->get($path));
    $image = imagecreatefromstring((string) Storage::disk('public')->get($path));
    $topRight = imagecolorsforindex($image, (int) imagecolorat($image, 19, 0));

    // 40×20 turned 90° clockwise: 20×40 with the red corner now at the top right.
    expect([$size[0], $size[1]])->toBe([20, 40])
        ->and($topRight['red'])->toBeGreaterThan(200);
});

it('stores files unchanged without orientImagesFromExif', function (): void {
    $user = imageToolingUser();
    $this->actingAs($user);
    $contents = jpegWithOrientation(40, 20, 6);
    $key = imageToolingUpload(UploadedFile::fake()->createWithContent('photo.jpg', $contents));

    imageToolingActAs($user);
    $form = schema([FileUpload::make('photo')])->fill();
    $form->rawState(['photo' => [$key]]);

    expect(Storage::disk('public')->get($form->validate()['photo']))->toBe($contents);
});

it('only asks the browser to orient images when the server cannot', function (): void {
    $props = schema([FileUpload::make('photo')->orientImagesFromExif()])->fill()->toSchema()[0];

    expect($props['orientImagesFromExif'] ?? null)->toBe(FileUpload::canOrientImagesOnServer() ? null : true);
});

it('parses aspect ratios', function (string $ratio, ?float $expected): void {
    expect(FileUpload::parseAspectRatio($ratio))->toBe($expected);
})->with([['16:9', 16 / 9], ['4/3', 4 / 3], ['1.5', 1.5], ['0:1', null], ['x', null]]);
