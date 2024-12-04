<?php

namespace App\Http\Controllers;

use App\Enums\GameSessionState;
use App\Enums\PlayerColor;
use App\Events\ConnectEvent;
use App\Models\GameSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class GameSessionController extends Controller {
    public function index(Request $request): \Inertia\ResponseFactory | \Inertia\Response | \Illuminate\Http\Response {
        $user = AuthService::getUser();

        $sessions = GameSession::where('session_state', GameSessionState::Waiting);
        foreach(PlayerColor::values() as $color) {
            $sessions = $sessions->where(function (Builder $query) use ($user, $color) {
                $query
                    ->whereNull('player_'.$color.'_id')
                    ->orWhere('player_'.$color.'_id', '!=', $user['id']);
            });
        }
        $sessions = $sessions->get();
        $current_session = $user->getCurrentSession();
        if(!is_null($current_session)) {
            $current_session = $current_session->getPublic();
        }

        if($request->expectsJson()) {
            return response([
                'user' => $user->getPublic(),
                'createdSession' => $current_session,
                'sessions' => $sessions,
            ])->withHeaders(['Content-Type' => 'application/json']);
        }

        return inertia('Sessions', ['user' => $user->getPublic(), 'sessions' => $sessions, 'userSession' => $current_session]);
    }

    public function create(Request $request): \Inertia\ResponseFactory | \Inertia\Response | \Illuminate\Http\RedirectResponse | \Illuminate\Http\Response {
        $user = AuthService::getUser();
        $currentUserSessions = $user->getCurrentSession();

        if($request->expectsJson()) {
            return response([
                'message' => 'To create a new lobby do: POST '.route('profile.store')
            ])->withHeaders(['Content-Type' => 'application/json']);
        }

        if(!is_null($currentUserSessions)) {
            return redirect()->route('lobby.index')->with('flash', 'You are already in another lobby.');
        }

        return inertia('SessionCreate', ['user' => !is_null($user) ? $user->getPublic() : null]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse | \Illuminate\Http\Response {
        /** @var \App\Models\User */
        $user = AuthService::getUser();

        $current_session = $user->getCurrentSession();
        if(!is_null($current_session)) {
            return response(['message' => 'You have already started a lobby.'])->withHeaders(['Content-Type' => 'application/json']);
        }

        $game_session = new GameSession();

        $valid_colors = $game_session->getValidPlayerColors();
        $host_color = $valid_colors[rand(0,count($valid_colors) - 1)];
        $game_session['player_'.$host_color.'_id'] = $user['id'];
        $game_session->save();

        if($request->expectsJson()) {
            return response($game_session)->withHeaders(['Content-Type' => 'application/json']);
        }

        return redirect()->route('lobby.index');
    }

    public function show(): \Illuminate\Http\Response {
        $user = AuthService::getUser();
        $current_session = $user->getCurrentSession();
        if(!is_null($current_session)) $current_session = $current_session->getPublic();

        return response([
            'createdSession' => $current_session,
        ])->withHeaders(['Content-Type' => 'application/json']);
    }

    public function join(Request $request, GameSession $game_session): \Inertia\ResponseFactory | \Inertia\Response | \Illuminate\Http\RedirectResponse | \Illuminate\Http\Response {
        $user = AuthService::getUser();

        if($game_session['session_state'] != GameSessionState::Waiting->value) {
            if($request->expectsJson()) {
                return response(['message' => 'Room is not accepting users.'])->withHeaders(['Content-Type' => 'application/json']);
            } else {
                return redirect()->route('lobby.index')->with('flash', 'Room is not accepting users.');
            }
        }

        $potentially_joined_session = $user->getCurrentSession();
        if(!is_null($potentially_joined_session)) {
            $message = 'You are alredy in another lobby.';

            if($potentially_joined_session['id'] == $game_session['id']) {
                $message = 'You are already in this lobby.';
            }

            if($request->expectsJson()) {
                return response(['message' => $message])->withHeaders(['Content-Type' => 'application/json']);
            } else {
                return redirect()->route('lobby.index')->with('flash', $message);
            }
        }

        $valid_colors = $game_session->getEmptyPlayerColors();
        if(count($valid_colors) == 0) {
            if($request->expectsJson()) {
                return response(['message' => 'Game is full.'])->withHeaders(['Content-Type' => 'application/json']);
            } else {
                return redirect()->route('lobby.index')->with('flash', 'Game is full.');
            }
        }

        $user_color = $valid_colors[rand(0,count($valid_colors) - 1)];
        $game_session['player_'.$user_color.'_id'] = $user['id'];
        if(count($valid_colors) == 1) {
            $game_session['session_state'] = GameSessionState::Playing->value;
            // TODO: Broadcast a game started event
        }
        $game_session->save();
        // TODO: Broadcast a user connected event

        if($request->expectsJson()) {
            return response($game_session)->withHeaders(['Content-Type' => 'application/json']);
        }

        broadcast(new ConnectEvent($game_session));

        return redirect()->route('lobby.index')->with('flash', 'Joined game!');
    }

    public function search(Request $request): \Inertia\ResponseFactory | \Inertia\Response | \Illuminate\Http\RedirectResponse | \Illuminate\Http\Response {
        /** @var \App\Models\User */
        $user = AuthService::getUser();

        $potential_session = $user->getCurrentSession();

        if(!is_null($potential_session)) {
            if($request->expectsJson()) {
                return response(['message' => 'You are alredy in another lobby.'])->withHeaders(['Content-Type' => 'application/json']);
            } else {
                return redirect()->route('lobby.index')->with('flash', 'You are alredy in another lobby.');
            }
        }

        $available_sessions = GameSession::where('session_state', GameSessionState::Waiting);
        foreach(PlayerColor::values() as $color) {
            $available_sessions = $available_sessions->where(function (Builder $query) use ($user, $color) {
                $query
                    ->whereNull('player_'.$color.'_id')
                    ->orWhere('player_'.$color.'_id', '!=', $user['id']);
            });
        }
        $available_sessions = $available_sessions->get();

        $game_session = $available_sessions[rand(0, count($available_sessions) - 1)];
        $valid_colors = $game_session->getEmptyPlayerColors();
        $user_color = $valid_colors[rand(0,count($valid_colors) - 1)];
        $game_session['player_'.$user_color.'_id'] = $user['id'];
        if(count($valid_colors) == 1) {
            $game_session['session_state'] = GameSessionState::Playing->value;
            // TODO: Broadcast a game started event
        }
        $game_session->save();
        // TODO: Broadcast a user connected event

        return $game_session;
    }
}