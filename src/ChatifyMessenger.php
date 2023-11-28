<?php

namespace Chatify;

use App\Models\User;
use Chatify\Models\ChFavorite as Favorite;
use Chatify\Models\ChMessage;
use Chatify\Models\ChMessage as Message;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Pusher\Pusher;

class ChatifyMessenger
{
    public $pusher;

    /**
     * Get max file's upload size in MB.
     */
    public function getMaxUploadSize(): int
    {
        return config('chatify.attachments.max_upload_size') * 1048576;
    }

    public function __construct()
    {
        $this->pusher = new Pusher(
            config('chatify.pusher.key'),
            config('chatify.pusher.secret'),
            config('chatify.pusher.app_id'),
            config('chatify.pusher.options'),
        );
    }

    /**
     * This method returns the allowed image extensions
     * to attach with the message.
     */
    public function getAllowedImages(): array
    {
        return config('chatify.attachments.allowed_images');
    }

    /**
     * This method returns the allowed file extensions
     * to attach with the message.
     */
    public function getAllowedFiles(): array
    {
        return config('chatify.attachments.allowed_files');
    }

    /**
     * Returns an array contains messenger's colors
     */
    public function getMessengerColors(): array
    {
        return config('chatify.colors');
    }

    /**
     * Returns a fallback primary color.
     */
    public function getFallbackColor(): string
    {
        $colors = $this->getMessengerColors();

        return count($colors) > 0 ? $colors[0] : '#000000';
    }

    /**
     * Trigger an event using Pusher
     */
    public function push(string $channel, string $event, array $data): object
    {
        return $this->pusher->trigger($channel, $event, $data);
    }

    /**
     * Authentication for pusher
     */
    public function pusherAuth(User $requestUser, User $authUser, string $channelName, string $socket_id): string|JsonResponse
    {
        // Auth data
        $authData = json_encode([
            'user_id' => $authUser->id,
            'user_info' => [
                'name' => $authUser->name,
            ],
        ]);
        // check if user authenticated
        if (Auth::check()) {
            if ($requestUser->id == $authUser->id) {
                return $this->pusher->socket_auth(
                    $channelName,
                    $socket_id,
                    $authData
                );
            }

            // if not authorized
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // if not authenticated
        return response()->json(['message' => 'Not authenticated'], 403);
    }

    /**
     * Fetch & parse message and return the message card
     * view as a response.
     */
    public function parseMessage(Model $prefetchedMessage = null, int $id = null)
    {
        $msg = null;
        $attachment = null;
        $attachment_type = null;
        $attachment_title = null;
        if ((bool) $prefetchedMessage) {
            $msg = $prefetchedMessage;
        } else {
            $msg = Message::where('id', $id)->first();
            if (! $msg) {
                return [];
            }
        }
        if (isset($msg->attachment)) {
            $attachmentOBJ = json_decode($msg->attachment);
            $attachment = $attachmentOBJ->new_name;
            $attachment_title = htmlentities(trim($attachmentOBJ->old_name), ENT_QUOTES, 'UTF-8');
            $ext = pathinfo($attachment, PATHINFO_EXTENSION);
            $attachment_type = in_array($ext, $this->getAllowedImages()) ? 'image' : 'file';
        }

        return [
            'id' => $msg->id,
            'from_id' => $msg->from_id,
            'to_id' => $msg->to_id,
            'message' => $msg->body,
            'attachment' => (object) [
                'file' => $attachment,
                'title' => $attachment_title,
                'type' => $attachment_type,
            ],
            'timeAgo' => $msg->created_at->diffForHumans(),
            'created_at' => $msg->created_at->toIso8601String(),
            'isSender' => ($msg->from_id == Auth::user()->id),
            'seen' => $msg->seen,
        ];
    }

    /**
     * Return a message card with the given data.
     */
    public function messageCard(array $data, bool $renderDefaultCard = false): string
    {
        if (! $data) {
            return '';
        }
        if ($renderDefaultCard) {
            $data['isSender'] = false;
        }

        return view('Chatify::layouts.messageCard', $data)->render();
    }

    /**
     * Default fetch messages query between a Sender and Receiver.
     */
    public function fetchMessagesQuery(int $user_id): Message|Builder
    {
        return Message::where('from_id', Auth::user()->id)->where('to_id', $user_id)
            ->orWhere('from_id', $user_id)->where('to_id', Auth::user()->id);
    }

    /**
     * create a new message to database
     */
    public function newMessage(array $data): Message
    {
        $message = new Message();
        $message->from_id = $data['from_id'];
        $message->to_id = $data['to_id'];
        $message->body = $data['body'];
        $message->attachment = $data['attachment'];
        $message->save();

        return $message;
    }

    /**
     * Make messages between the sender [Auth user] and
     * the receiver [User id] as seen.
     */
    public function makeSeen(int $user_id): bool
    {
        Message::where('from_id', $user_id)
            ->where('to_id', Auth::user()->id)
            ->where('seen', 0)
            ->update(['seen' => 1]);

        return 1;
    }

    /**
     * Get last message for a specific user
     */
    public function getLastMessageQuery(int $user_id): Message|Collection|Builder|Model|null
    {
        return $this->fetchMessagesQuery($user_id)->latest()->first();
    }

    /**
     * Count Unseen messages
     */
    public function countUnseenMessages(int $user_id): int
    {
        return Message::where('from_id', $user_id)->where('to_id', Auth::user()->id)->where('seen', 0)->count();
    }

    /**
     * Get user list's item data [Contact Itme]
     * (e.g. User data, Last message, Unseen Counter...)
     */
    public function getContactItem(User|ChMessage $user): string
    {
        try {
            // get last message
            $lastMessage = $this->getLastMessageQuery($user->id);
            // Get Unseen messages counter
            $unseenCounter = $this->countUnseenMessages($user->id);
            if ($lastMessage) {
                $lastMessage->created_at = $lastMessage->created_at->toIso8601String();
                $lastMessage->timeAgo = $lastMessage->created_at->diffForHumans();
            }

            return view('Chatify::layouts.listItem', [
                'get' => 'users',
                'user' => $this->getUserWithAvatar($user),
                'lastMessage' => $lastMessage,
                'unseenCounter' => $unseenCounter,
            ])->render();
        } catch (\Throwable $th) {
            throw new Exception($th->getMessage());
        }
    }

    /**
     * Get user with avatar (formatted).
     */
    public function getUserWithAvatar(User|ChMessage $user): User|ChMessage
    {
        if ($user->avatar == 'avatar.png' && config('chatify.gravatar.enabled')) {
            $imageSize = config('chatify.gravatar.image_size');
            $imageset = config('chatify.gravatar.imageset');
            $user->avatar = 'https://www.gravatar.com/avatar/'.md5(strtolower(trim($user->email))).'?s='.$imageSize.'&d='.$imageset;
        } else {
            $user->avatar = self::getUserAvatarUrl($user->avatar);
        }

        return $user;
    }

    /**
     * Check if a user in the favorite list
     */
    public function inFavorite(int $user_id): bool
    {
        return Favorite::where('user_id', Auth::user()->id)
            ->where('favorite_id', $user_id)->count() > 0;
    }

    /**
     * Make user in favorite list
     */
    public function makeInFavorite(int $user_id, int $action): bool
    {
        if ($action > 0) {
            // Star
            $star = new Favorite();
            $star->user_id = Auth::user()->id;
            $star->favorite_id = $user_id;
            $star->save();

            return (bool) $star;
        } else {
            // UnStar
            $star = Favorite::where('user_id', Auth::user()->id)->where('favorite_id', $user_id)->delete();

            return (bool) $star;
        }
    }

    /**
     * Get shared photos of the conversation
     */
    public function getSharedPhotos(int $user_id): array
    {
        $images = []; // Default
        // Get messages
        $msgs = $this->fetchMessagesQuery($user_id)->orderBy('created_at', 'DESC');
        if ($msgs->count() > 0) {
            foreach ($msgs->get() as $msg) {
                // If message has attachment
                if ($msg->attachment) {
                    $attachment = json_decode($msg->attachment);
                    // determine the type of the attachment
                    in_array(pathinfo($attachment->new_name, PATHINFO_EXTENSION), $this->getAllowedImages())
                    ? array_push($images, $attachment->new_name) : '';
                }
            }
        }

        return $images;
    }

    /**
     * Delete Conversation
     */
    public function deleteConversation(int $user_id): bool
    {
        try {
            foreach ($this->fetchMessagesQuery($user_id)->get() as $msg) {
                // delete file attached if exist
                if (isset($msg->attachment)) {
                    $path = config('chatify.attachments.folder').'/'.json_decode($msg->attachment)->new_name;
                    if (self::storage()->exists($path)) {
                        self::storage()->delete($path);
                    }
                }
                // delete from database
                $msg->delete();
            }

            return 1;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Delete message by ID
     */
    public function deleteMessage(string $id): bool
    {
        try {
            $msg = Message::where('from_id', auth()->id())->where('id', $id)->firstOrFail();
            if (isset($msg->attachment)) {
                $path = config('chatify.attachments.folder').'/'.json_decode($msg->attachment)->new_name;
                if (self::storage()->exists($path)) {
                    self::storage()->delete($path);
                }
            }
            $msg->delete();

            return 1;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Return a storage instance with disk name specified in the config.
     */
    public function storage()
    {
        return Storage::disk(config('chatify.storage_disk_name'));
    }

    /**
     * Get user avatar url.
     */
    public function getUserAvatarUrl(string $user_avatar_name): string
    {
        return self::storage()->url(config('chatify.user_avatar.folder').'/'.$user_avatar_name);
    }

    /**
     * Get attachment's url.
     */
    public function getAttachmentUrl(string $attachment_name): string
    {
        return self::storage()->url(config('chatify.attachments.folder').'/'.$attachment_name);
    }
}
