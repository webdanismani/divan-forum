@include('errors.layout', [
    'code' => 403,
    'title' => __('No permission'),
    'message' => $exception->getMessage() && $exception->getMessage() !== 'This action is unauthorized.'
        ? $exception->getMessage()
        : __('You do not have permission to view this page or perform this action.'),
])
