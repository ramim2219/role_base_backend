<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeValue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AttributeValueController extends Controller
{
    private const OK             = 1200;
    private const NOT_FOUND      = 2001;
    private const FORBIDDEN      = 3001;
    private const DUPLICATE      = 3002;
    private const VALIDATION_ERR = 4000;
    private const SERVER_ERR     = 5000;

    private function ok($message = 'Success', $data = null)
    {
        return response()->json([
            'messageCode' => self::OK,
            'message'     => $message,
            'data'        => $data,
        ]);
    }

    private function fail($message, $code = self::SERVER_ERR, $data = null)
    {
        return response()->json([
            'messageCode' => $code,
            'message'     => $message,
            'data'        => $data,
        ]);
    }

    // Ownership guard: the parent attribute must be created by this user
    private function ownsAttribute(int $attributeId, $authUser): bool
    {
        return Attribute::where('id', $attributeId)
            ->where('created_by', $authUser->id)
            ->exists();
    }

    private function findOwnedValue(int $id, $authUser): ?AttributeValue
    {
        $value = AttributeValue::with('attribute')->find($id);
        if (!$value || !$value->attribute) return null;
        if ((int) $value->attribute->created_by !== (int) $authUser->id) return null;
        return $value;
    }

    // ═════════════════════════════════════════════════════
    // 1. LIST BY ATTRIBUTE
    // GET /api/AttributeValue/get_by_attribute?attribute_id=X
    // ═════════════════════════════════════════════════════
    public function getByAttribute(Request $request)
    {
        try {
            $authUser    = $request->user();
            $attributeId = (int) $request->query('attribute_id');

            if (!$attributeId) {
                return $this->fail('attribute_id is required.', self::VALIDATION_ERR);
            }
            if (!$this->ownsAttribute($attributeId, $authUser)) {
                return $this->fail(
                    'Attribute not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            $rows = AttributeValue::where('attribute_id', $attributeId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn ($v) => $v->toApiArray());

            return $this->ok('Attribute values fetched successfully.', $rows);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 2. CREATE
    // POST /api/AttributeValue/save
    //   attribute_id, value, display_name, sort_order?, status?
    // ═════════════════════════════════════════════════════
    public function save(Request $request)
    {
        try {
            $authUser = $request->user();

            $v = Validator::make($request->all(), [
                'attribute_id' => 'required|integer|exists:attributes,id',
                'value'        => 'required|string|max:100',
                'display_name' => 'required|string|max:150',
                'sort_order'   => 'nullable|integer|min:0',
                'status'       => 'nullable|integer|in:0,1',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $attributeId = (int) $request->input('attribute_id');
            if (!$this->ownsAttribute($attributeId, $authUser)) {
                return $this->fail(
                    'Attribute not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            $value = strtolower(trim($request->input('value')));

            $dup = AttributeValue::where('attribute_id', $attributeId)
                ->where('value', $value)
                ->exists();

            if ($dup) {
                return $this->fail(
                    'This value already exists for the attribute.',
                    self::DUPLICATE
                );
            }

            $row = AttributeValue::create([
                'attribute_id' => $attributeId,
                'value'        => $value,
                'display_name' => trim($request->input('display_name')),
                'sort_order'   => (int) $request->input('sort_order', 0),
                'status'       => (int) $request->input('status', 1),
            ]);

            return $this->ok('Attribute value created successfully.', ['id' => $row->id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 3. UPDATE
    // PUT /api/AttributeValue/update
    // ═════════════════════════════════════════════════════
    public function update(Request $request)
    {
        try {
            $authUser = $request->user();
            $id       = (int) $request->input('id');

            if (!$id) {
                return $this->fail('id is required.', self::VALIDATION_ERR);
            }

            $row = $this->findOwnedValue($id, $authUser);
            if (!$row) {
                return $this->fail(
                    'Attribute value not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            $v = Validator::make($request->all(), [
                'value'        => 'sometimes|string|max:100',
                'display_name' => 'sometimes|string|max:150',
                'sort_order'   => 'sometimes|integer|min:0',
                'status'       => 'sometimes|integer|in:0,1',
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors()->first(), self::VALIDATION_ERR);
            }

            $data = [];
            if ($request->filled('value')) {
                $data['value'] = strtolower(trim($request->input('value')));
            }
            if ($request->filled('display_name')) {
                $data['display_name'] = trim($request->input('display_name'));
            }
            if ($request->has('sort_order')) {
                $data['sort_order'] = (int) $request->input('sort_order');
            }
            if ($request->has('status')) {
                $data['status'] = (int) $request->input('status');
            }

            // Duplicate check within the same attribute
            $effValue = $data['value'] ?? $row->value;
            $dup = AttributeValue::where('attribute_id', $row->attribute_id)
                ->where('value', $effValue)
                ->where('id', '!=', $id)
                ->exists();

            if ($dup) {
                return $this->fail(
                    'This value already exists for the attribute.',
                    self::DUPLICATE
                );
            }

            if (empty($data)) {
                return $this->fail('No fields to update.', self::VALIDATION_ERR);
            }

            $row->update($data);

            return $this->ok(
                'Attribute value updated successfully.',
                $row->fresh()->toApiArray()
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════
    // 4. DELETE
    // DELETE /api/AttributeValue/delete
    // ═════════════════════════════════════════════════════
    public function delete(Request $request)
    {
        try {
            $authUser = $request->user();

            $id = (int) ($request->input('id') ?? $request->query('id') ?? 0);
            if (!$id) {
                return $this->fail('id is required.', self::VALIDATION_ERR);
            }

            $row = $this->findOwnedValue($id, $authUser);
            if (!$row) {
                return $this->fail(
                    'Attribute value not found or not under your administration.',
                    self::NOT_FOUND
                );
            }

            $row->delete();

            return $this->ok('Attribute value deleted successfully.', ['id' => $id]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}