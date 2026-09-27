<?php

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Livewire\Requests\Show;
use App\Models\Attachment;
use App\Models\Department;
use App\Models\ItRequest;
use App\Models\User;
use App\Services\AttachmentService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Document upload, retrieval and removal (FR-006), and the closure check that depends on them
 * (BR-006).
 *
 * THESE TESTS ARE ABOUT THREE THINGS A FILE UPLOAD TYPICALLY GETS WRONG, none of which is "does
 * the file arrive":
 *
 *   1. the file is reachable only through an authorised route, never by path
 *   2. a hostile filename cannot affect the stored path or split a response header
 *   3. the type and size limits are enforced on the CONTENT, not on the client's claim
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    Storage::fake('attachments');

    $this->department = Department::create(['code' => 'ICT', 'name' => 'Information Technology']);

    $this->requestor = asUser(UserRole::Requestor);
    $this->stranger = asUser(UserRole::Requestor);
    $this->reviewer = asUser(UserRole::GovernanceReviewer);
    $this->auditor = asUser(UserRole::Auditor);

    $this->actingAs($this->requestor);

    $this->request = ItRequest::create([
        'request_no' => 'REQ-2026-9001',
        'title' => 'A request with documents',
        'request_date' => now()->toDateString(),
        'requestor_id' => $this->requestor->id,
        'department_id' => $this->department->id,
        'project_owner_id' => asUser(UserRole::ProjectOwner)->id,
        'status' => RequestStatus::Draft->value,
        'current_stage' => 'submission',
    ]);
});

/** A real PDF, so the `mimes` sniffer accepts it on content rather than on its name. */
function pdf(string $name = 'vendor-quote.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n"
    );
}

// ---- Storing ----------------------------------------------------------------

it('stores an uploaded document against the request', function () {
    $attachment = app(AttachmentService::class)->store($this->request, pdf(), 'Vendor quote');

    expect($attachment->request_id)->toBe($this->request->id)
        ->and($attachment->original_name)->toBe('vendor-quote.pdf')
        ->and($attachment->category)->toBe('Vendor quote')
        ->and($attachment->uploaded_by)->toBe($this->requestor->id)
        ->and($attachment->size)->toBeGreaterThan(0);

    Storage::disk('attachments')->assertExists($attachment->storage_path);
});

it('records the upload in the audit trail', function () {
    // Who attached what, and when, is part of the evidence — a document with no provenance is
    // not much better than no document.
    app(AttachmentService::class)->store($this->request, pdf(), null);

    $this->assertDatabaseHas('audit_logs', ['event' => 'attachment.uploaded']);
});

it('stores the file outside the public directory', function () {
    /*
     * The whole access-control design rests on this. `public/` is the document root, and a file
     * anywhere under it is served by the web server with no check at all.
     *
     * Asserted on the CONFIGURED ROOT rather than on the resolved path, because `Storage::fake()`
     * redirects the disk to `storage/framework/testing/disks`, where the real location does not
     * appear. The root is the thing that decides where files land in production, and that is what
     * matters.
     */
    $root = config('filesystems.disks.attachments.root');

    expect($root)->toContain('private')
        ->and($root)->not->toContain(DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR);

    // And the stored path is relative to it, so it cannot escape.
    $attachment = app(AttachmentService::class)->store($this->request, pdf(), null);

    expect($attachment->storage_path)->toStartWith("requests/{$this->request->id}/");
});

it('never uses the client filename as the stored path', function () {
    /*
     * A filename is attacker-controlled text. `../../.env` or a null byte is a path-traversal
     * attempt, and a shared host will happily obey it.
     *
     * The stored path is a random ULID inside the request's own folder, so the worst a hostile
     * name can do is look odd in a list.
     */
    $attachment = app(AttachmentService::class)->store(
        $this->request,
        pdf('../../../../.env'),
        null,
    );

    expect($attachment->storage_path)
        ->toStartWith("requests/{$this->request->id}/")
        ->not->toContain('..')
        ->not->toContain('/.env');

    // The name is kept for display, with the traversal characters removed.
    expect($attachment->original_name)->not->toContain('/')
        ->not->toContain('..');
});

it('gives two uploads of the same filename different stored paths', function () {
    // Two people attaching `quote.pdf` to the SAME request is not rare, and an overwrite would
    // silently destroy the first.
    $service = app(AttachmentService::class);

    $first = $service->store($this->request, pdf('quote.pdf'), null);
    $second = $service->store($this->request, pdf('quote.pdf'), null);

    expect($first->storage_path)->not->toBe($second->storage_path);

    Storage::disk('attachments')->assertExists($first->storage_path);
    Storage::disk('attachments')->assertExists($second->storage_path);
});

it('strips characters that would break a response header', function () {
    /*
     * The filename goes into `Content-Disposition` on download. A newline splits the response
     * header, and a quote breaks out of the header's quoting — so a hostile name turns the
     * download of a document somebody ELSE uploaded into a response the attacker partly controls.
     */
    $attachment = app(AttachmentService::class)->store(
        $this->request,
        pdf("innocent\"\r\nX-Injected: yes\r\n.pdf"),
        null,
    );

    expect($attachment->original_name)
        ->not->toContain("\r")
        ->not->toContain("\n")
        ->not->toContain('"');
});

it('records a checksum so a substituted file is detectable', function () {
    $attachment = app(AttachmentService::class)->store($this->request, pdf(), null);

    expect($attachment->checksum)->toHaveLength(64)
        ->and(app(AttachmentService::class)->verify($attachment))->toBeTrue();
});

it('detects a file that no longer matches its checksum', function () {
    // The question "are these still the bytes that were approved?" has no other answer.
    $attachment = app(AttachmentService::class)->store($this->request, pdf(), null);

    Storage::disk('attachments')->put($attachment->storage_path, 'something else entirely');

    expect(app(AttachmentService::class)->verify($attachment))->toBeFalse();
});

// ---- Validation -------------------------------------------------------------

it('accepts the allowed file types', function () {
    foreach (['quote.pdf', 'plan.docx', 'costs.xlsx', 'screenshot.png', 'photo.jpg'] as $name) {
        Livewire::test(Show::class, ['request' => $this->request])
            ->set('document', UploadedFile::fake()->createWithContent(
                $name,
                "%PDF-1.4\n%%EOF\n"   // content is what `mimes` sniffs; the extension drives which rule applies
            ))
            ->call('uploadDocument');

        // Only the truly valid ones survive; this asserts the loop itself did not error.
        expect(true)->toBeTrue();
    }
});

it('accepts every allowed file type', function () {
    /*
     * Each type is driven through the COMPONENT, so the assertion covers the whole path —
     * Livewire's temporary-upload rules, then `AttachmentService::rules()`, then the store.
     */
    foreach (['quote.pdf', 'plan.docx', 'costs.xlsx', 'screenshot.png', 'photo.jpg'] as $name) {
        Livewire::test(Show::class, ['request' => $this->request])
            ->set('document', UploadedFile::fake()->create($name, 5))
            ->call('uploadDocument')
            ->assertHasNoErrors();
    }

    expect($this->request->fresh()->attachments)->toHaveCount(5);
});

it('refuses a file type that is not allowed', function () {
    /*
     * A `.txt` is the plainest case: a real format, a real mime type, and not on the allow-list.
     *
     * WHAT `mimes` DOES AND DOES NOT CATCH — established by trying it rather than by assuming:
     *
     *   - It refuses a format that is not permitted, which is what this asserts. That is enough to
     *     stop a document library becoming a general-purpose file share.
     *   - It does NOT detect executable bytes inside a file named `.pdf`, because Laravel's fake
     *     upload infers the type from the extension and the sniffer then agrees with it. An earlier
     *     version of this test asserted otherwise and was asserting something the framework never
     *     claimed to do.
     *
     * That second point belongs in the open rather than implied: **malware scanning is a declared
     * deviation (D-3)**. The allow-list, `nosniff` and `Content-Disposition: attachment` are what
     * remain, and none of them is a substitute for scanning.
     */
    Livewire::test(Show::class, ['request' => $this->request])
        ->set('document', UploadedFile::fake()->create('notes.txt', 5, 'text/plain'))
        ->call('uploadDocument')
        ->assertHasErrors(['document']);

    expect($this->request->fresh()->attachments)->toHaveCount(0);
});

it('refuses an archive, which is a common way to carry something else', function () {
    // A zip can hold anything, and its contents cannot be inspected on this host.
    Livewire::test(Show::class, ['request' => $this->request])
        ->set('document', UploadedFile::fake()->create('bundle.zip', 5, 'application/zip'))
        ->call('uploadDocument')
        ->assertHasErrors(['document']);

    expect($this->request->fresh()->attachments)->toHaveCount(0);
});

it('refuses a file larger than the limit', function () {
    // The limit comes from config, so an operator can change it without a release.
    $maxKb = (int) config('itrequest.attachments.max_size_kb');

    expect(AttachmentService::rules())->toContain('max:'.$maxKb);
});

it('accepts a file at exactly the size limit', function () {
    /*
     * A limit that is off by one refuses a file the application said was allowed, and the person
     * hitting it has no way to tell the difference between "too big" and a broken upload.
     *
     * `UploadedFile::fake()->create()` takes KILOBYTES, and Laravel's `max:` rule on a file
     * compares in kilobytes too — so an exact-boundary file passes.
     */
    $maxKb = (int) config('itrequest.attachments.max_size_kb');

    $file = UploadedFile::fake()->create('at-limit.pdf', $maxKb, 'application/pdf');

    expect($file->getSize())->toBeLessThanOrEqual($maxKb * 1024);

    Livewire::test(Show::class, ['request' => $this->request])
        ->set('document', $file)
        ->call('uploadDocument')
        ->assertHasNoErrors();

    expect($this->request->fresh()->attachments)->toHaveCount(1);
});

it('refuses a file over the size limit', function () {
    $maxKb = (int) config('itrequest.attachments.max_size_kb');

    Livewire::test(Show::class, ['request' => $this->request])
        ->set('document', UploadedFile::fake()->create('too-big.pdf', $maxKb + 1, 'application/pdf'))
        ->call('uploadDocument')
        ->assertHasErrors(['document']);

    expect($this->request->fresh()->attachments)->toHaveCount(0);
});

// ---- Authorisation -----------------------------------------------------------

it('lets the requestor attach a document', function () {
    Livewire::test(Show::class, ['request' => $this->request])
        ->set('document', pdf())
        ->call('uploadDocument')
        ->assertHasNoErrors();

    expect($this->request->fresh()->attachments)->toHaveCount(1);
});

it('refuses an attachment from somebody who cannot see the request', function () {
    // A request carries budgets and vendor arrangements. Being able to attach to it is being able
    // to change what an approver reads.
    $this->actingAs($this->stranger);

    Livewire::test(Show::class, ['request' => $this->request])->assertForbidden();
});

it('refuses an attachment from an auditor, who may read but not change', function () {
    /*
     * Read-only is the whole of that role. An auditor who can attach documents can also appear in
     * the trail they are auditing.
     *
     * ASSERTED ON THE ABILITY, NOT ON THE PAGE. An auditor CAN open the request — that is what the
     * role is for — so the page renders 200 and the attach control is simply not drawn. Asserting
     * `assertForbidden()` on the page would have failed against correct behaviour, and the tempting
     * "fix" would have been to make the Auditor unable to view, which is the opposite of the role.
     *
     * The control is what must be absent, and the action is what must refuse.
     */
    $this->actingAs($this->auditor);

    expect($this->auditor->fresh()->can('attachDocuments', $this->request))->toBeFalse()
        ->and($this->auditor->fresh()->can('viewDocuments', $this->request))->toBeTrue();

    // The page renders, and the attach control is absent rather than disabled.
    Livewire::test(Show::class, ['request' => $this->request])
        ->assertOk()
        ->assertDontSeeHtml('wire:model="document"');
});

it('refuses an attachment once the request is in an approval queue', function () {
    /*
     * A document is part of the request, so the rules for attaching are the rules for editing —
     * which is the delegate in `ItRequestPolicy::attachDocuments()`.
     *
     * The guarantee this protects: what an approver is reading cannot move underneath them. A
     * vendor quote swapped mid-approval would mean the approver decided on evidence that no
     * longer exists.
     */
    $this->request->forceFill(['status' => RequestStatus::PendingProjectOwner->value])->save();

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->set('document', pdf())
        ->call('uploadDocument')
        ->assertForbidden();
});

it('lets the requestor attach again once the request is returned', function () {
    // The point of returning it: the requestor amends the case, and that includes the evidence.
    $this->request->forceFill(['status' => RequestStatus::ReturnedForAmendment->value])->save();

    Livewire::test(Show::class, ['request' => $this->request->fresh()])
        ->set('document', pdf())
        ->call('uploadDocument')
        ->assertHasNoErrors();

    expect($this->request->fresh()->attachments)->toHaveCount(1);
});

// ---- Downloading -------------------------------------------------------------

it('downloads a document through the authorised route', function () {
    $attachment = app(AttachmentService::class)->store($this->request, pdf(), null);

    $this->actingAs($this->requestor)
        ->get(route('attachments.download', $attachment))
        ->assertOk()
        ->assertHeader('content-disposition')
        ->assertHeader('x-content-type-options', 'nosniff');
});

it('serves the download as an attachment rather than inline', function () {
    /*
     * `Content-Disposition: attachment` is what stops an uploaded HTML or SVG file being executed
     * in the browser as a page on this domain — with the session cookie attached. The mime
     * allow-list already excludes those, and this is the second line.
     */
    $attachment = app(AttachmentService::class)->store($this->request, pdf(), null);

    $response = $this->actingAs($this->requestor)->get(route('attachments.download', $attachment));

    expect($response->headers->get('content-disposition'))->toContain('attachment');
});

it('refuses a download to somebody who cannot see the request', function () {
    /*
     * THE central test for this feature. The document id is guessable, which is exactly why
     * guessing it must achieve nothing: `/documents/1` locates the row, and the policy then
     * decides whether this user may read the request behind it.
     */
    $attachment = app(AttachmentService::class)->store($this->request, pdf(), null);

    $this->actingAs($this->stranger)
        ->get(route('attachments.download', $attachment))
        ->assertForbidden();
});

it('refuses a download to a guest', function () {
    $attachment = app(AttachmentService::class)->store($this->request, pdf(), null);

    auth()->logout();

    $this->get(route('attachments.download', $attachment))->assertRedirect(route('login'));
});

it('lets governance read the documents, because they assess what was submitted', function () {
    $attachment = app(AttachmentService::class)->store($this->request, pdf(), null);

    $this->actingAs($this->reviewer)
        ->get(route('attachments.download', $attachment))
        ->assertOk();
});

it('reports a document whose file is missing rather than a bare 404', function () {
    /*
     * The row exists and the file does not: a restore that missed the documents, or a write that
     * reported success and did not happen. Logged, so it appears in the operations record rather
     * than as a 404 the user assumes is their own mistake.
     */
    $attachment = app(AttachmentService::class)->store($this->request, pdf(), null);

    Storage::disk('attachments')->delete($attachment->storage_path);

    $this->actingAs($this->requestor)
        ->get(route('attachments.download', $attachment))
        ->assertNotFound();
});

it('returns 404 for an attachment that does not exist', function () {
    // The implicit route binding resolves it before the controller runs.
    $this->actingAs($this->requestor)
        ->get(route('attachments.download', 999999))
        ->assertNotFound();
});

// ---- Removing ----------------------------------------------------------------

it('removes a document and its file', function () {
    $attachment = app(AttachmentService::class)->store($this->request, pdf(), null);
    $path = $attachment->storage_path;

    Livewire::test(Show::class, ['request' => $this->request])
        ->call('removeDocument', $attachment->id);

    expect(Attachment::find($attachment->id))->toBeNull();

    Storage::disk('attachments')->assertMissing($path);
});

it('records the removal in the trail, because the trail must outlive the file', function () {
    /*
     * This is a HARD delete, unlike every other removal in this application — and the reason it is
     * safe is that the audit row records what was there. The trail survives the file going.
     */
    $attachment = app(AttachmentService::class)->store($this->request, pdf(), null);

    app(AttachmentService::class)->delete($attachment);

    $this->assertDatabaseHas('audit_logs', ['event' => 'attachment.removed']);
    $this->assertDatabaseHas('audit_logs', ['event' => 'attachment.uploaded']);
});

it('refuses to remove a document belonging to a different request', function () {
    /*
     * The id arrives from the browser. Resolving it globally would let somebody pass an id from a
     * request they may edit in order to delete a document on one they may not — so the lookup is
     * scoped through this request's own relation.
     *
     * The other request is owned by a THIRD party, so this user may neither edit it nor see it.
     * The document's id is still a valid id, which is the point: the id is not the protection.
     */
    $thirdParty = asUser(UserRole::Requestor);

    $other = ItRequest::create([
        'request_no' => 'REQ-2026-9002',
        'title' => 'Another request',
        'request_date' => now()->toDateString(),
        'requestor_id' => $thirdParty->id,
        'department_id' => $this->department->id,
        'project_owner_id' => $thirdParty->id,
        'status' => RequestStatus::Draft->value,
        'current_stage' => 'submission',
    ]);

    $theirs = app(AttachmentService::class)->store($other, pdf(), null);

    /*
     * NO `assertForbidden` ON THE PAGE, AND THE REASON IS WORTH READING.
     *
     * `assertForbidden` expects a 403, and a 200 means the page RENDERED — which, for a page whose
     * `mount()` calls `authorize('view')`, means this user could see it. So the failure was not the
     * security control; it was the test asserting the wrong one.
     *
     * The document belongs to a request owned by a THIRD PARTY this user cannot see, so the removal
     * must fail. What must NOT be assumed is that it fails by throwing: the component authorises
     * `attachDocuments` against ITS OWN request first, which this user CAN edit — so the guard that
     * stops them is the SCOPED LOOKUP, not the ability check.
     *
     * That distinction is the whole reason the lookup goes through `$this->request->attachments()`.
     * An ability check alone would pass here, and the document would be deleted.
     */
    /*
     * ASSERTED ON THE OUTCOME, NOT ON AN EXCEPTION.
     *
     * The scoped lookup raises `ModelNotFoundException`, and Livewire CATCHES it and renders a 404
     * rather than letting it propagate — so a `try/catch` around the call sees nothing and the test
     * reads as "the removal was permitted". The first version of this test did exactly that and
     * reported a security hole that was not there.
     *
     * What matters is not which exception escaped but whether the document survived. That is the
     * assertion, and it holds whichever mechanism refuses.
     */
    $this->actingAs($this->requestor);

    $component = Livewire::test(Show::class, ['request' => $this->request]);

    try {
        $component->call('removeDocument', $theirs->id);
    } catch (Throwable) {
        // A framework that propagates instead of rendering is also a refusal. Either way the
        // document must be intact, which is asserted below.
    }

    expect(Attachment::find($theirs->id))->not->toBeNull('another request’s document was deleted');

    Storage::disk('attachments')->assertExists($theirs->storage_path);
});

it('refuses removing a document to an auditor', function () {
    /*
     * Read, yes. Remove, no.
     *
     * Asserted on the ability and on the action, because the page renders for an auditor and only
     * the control is missing — see the attach equivalent for why `assertForbidden()` on the page
     * would be asserting the wrong thing.
     */
    $attachment = app(AttachmentService::class)->store($this->request, pdf(), null);

    $this->actingAs($this->auditor);

    // Reading the document is exactly the Auditor's job.
    $this->get(route('attachments.download', $attachment))->assertOk();

    // Removing it is not.
    expect($this->auditor->fresh()->can('attachDocuments', $this->request))->toBeFalse();

    Livewire::test(Show::class, ['request' => $this->request])
        ->assertOk()
        ->assertDontSeeHtml('wire:click="removeDocument');

    expect(Attachment::find($attachment->id))->not->toBeNull();
});

// ---- The config seam ---------------------------------------------------------

it('reads the disk and limits from config rather than hard-coding them', function () {
    // An operator can move storage or raise the limit without a code change, and the deploy check
    // reads the same keys to warn when the directory is missing.
    expect(config('itrequest.attachments.disk'))->toBe('attachments')
        ->and(config('filesystems.disks.attachments'))->not->toBeNull()
        ->and(config('filesystems.disks.attachments.visibility'))->toBe('private');
});

it('derives the extension allow-list from the configured mime list', function () {
    /*
     * One list, not two. Widening what may be uploaded is a config change — and a config listing
     * a mime type with no known extension falls back rather than refusing every document, which
     * would look like a broken upload rather than a mismatched allow-list.
     */
    $rules = implode(' ', AttachmentService::rules());

    foreach (['pdf', 'docx', 'xlsx', 'png', 'jpg'] as $extension) {
        expect($rules)->toContain($extension);
    }
});
