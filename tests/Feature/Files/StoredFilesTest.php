<?php

declare(strict_types=1);

use App\Kernel\Reference;
use App\Modules\Files\Actions\DeleteFile;
use App\Modules\Files\Actions\StoreFile;
use App\Modules\Files\Queries\FilesOf;
use App\Modules\Files\Queries\ReadFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('files-local');
});

it('stores a file for a record with its checksum, and reads it back', function (): void {
    $invoice = Reference::to('invoice', 12);

    $file = app(StoreFile::class)->store($invoice, 'xml', 'IT01234567890_00001.xml', '<xml/>', 'application/xml');

    expect($file->owner->equals($invoice))->toBeTrue()
        ->and($file->kind)->toBe('xml')
        ->and($file->filename)->toBe('IT01234567890_00001.xml')
        ->and($file->size)->toBe(6)
        ->and($file->sha256)->toBe(hash('sha256', '<xml/>'))
        ->and(app(ReadFile::class)->contents($file->id))->toBe('<xml/>')
        ->and(app(ReadFile::class)->find($file->id)?->id)->toBe($file->id);

    Storage::disk('files-local')->assertExists('invoice/12/'.basename(Storage::disk('files-local')->allFiles()[0]));
});

it('never reuses a path, even for the same file name', function (): void {
    $store = app(StoreFile::class);
    $a = $store->store(Reference::to('invoice', 1), 'pdf', 'invoice.pdf', 'first', 'application/pdf');
    $b = $store->store(Reference::to('invoice', 1), 'pdf', 'invoice.pdf', 'second', 'application/pdf');

    expect(app(ReadFile::class)->contents($a->id))->toBe('first')
        ->and(app(ReadFile::class)->contents($b->id))->toBe('second')
        ->and(Storage::disk('files-local')->allFiles())->toHaveCount(2);
});

it('lists the files of a record, newest first, and of many records in one query', function (): void {
    $store = app(StoreFile::class);
    $store->store(Reference::to('invoice', 1), 'pdf', 'a.pdf', 'a', 'application/pdf');
    $store->store(Reference::to('invoice', 1), 'xml', 'b.xml', 'b', 'application/xml');
    $store->store(Reference::to('invoice', 2), 'pdf', 'c.pdf', 'c', 'application/pdf');

    $files = app(FilesOf::class);

    expect(array_map(fn ($file) => $file->filename, $files->get(Reference::to('invoice', 1))))->toBe(['b.xml', 'a.pdf'])
        ->and(array_map(fn ($file) => $file->filename, $files->get(Reference::to('invoice', 1), 'pdf')))->toBe(['a.pdf'])
        ->and($files->get(Reference::to('invoice', 99)))->toBe([]);

    Illuminate\Support\Facades\DB::enableQueryLog();
    $many = $files->forMany([Reference::to('invoice', 1), Reference::to('invoice', 2), Reference::to('invoice', 3)]);

    expect(Illuminate\Support\Facades\DB::getQueryLog())->toHaveCount(1)
        ->and(array_map(fn (array $list) => count($list), $many))->toBe(['invoice:1' => 2, 'invoice:2' => 1, 'invoice:3' => 0]);
});

it('deletes a file and its record', function (): void {
    $file = app(StoreFile::class)->store(Reference::to('quote', 3), 'pdf', 'q.pdf', 'q', 'application/pdf');

    app(DeleteFile::class)->delete($file->id);
    app(DeleteFile::class)->delete($file->id);

    expect(app(ReadFile::class)->find($file->id))->toBeNull()
        ->and(Storage::disk('files-local')->allFiles())->toBe([]);
});

it('refuses a tampered file', function (): void {
    $file = app(StoreFile::class)->store(Reference::to('invoice', 1), 'xml', 'a.xml', 'original', 'application/xml');
    $path = Storage::disk('files-local')->allFiles()[0];
    Storage::disk('files-local')->put($path, 'tampered');

    expect(fn () => app(ReadFile::class)->contents($file->id))->toThrow(RuntimeException::class);
});

it('validates the kind and the file name', function (): void {
    $store = app(StoreFile::class);

    expect(fn () => $store->store(Reference::to('invoice', 1), 'PDF', 'a.pdf', 'a', 'application/pdf'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $store->store(Reference::to('invoice', 1), 'pdf', '', 'a', 'application/pdf'))->toThrow(InvalidArgumentException::class)
        ->and($store->store(Reference::to('invoice', 1), 'pdf', '../../etc/passwd', 'a', 'application/pdf')->filename)->toBe('passwd');
});
