// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Player adapters and server-authoritative tracking for Video Lesson.
 *
 * @module     mod_videolesson/player
 * @package   mod_videolesson
 * @copyright  2026 Eduardo Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/ajax', 'core/notification'], function (Ajax, Notification) {
    'use strict';

    var root;
    var config;
    var adapter;
    var sequence = 0;
    var sessionkey = '';
    var previous = null;
    var busy = false;
    var timer = null;
    var lastUnlockedMax = -1;

    var loadScript = function (src, ready) {
        return new Promise(function (resolve, reject) {
            if (ready && ready()) {
                resolve();
                return;
            }
            var existing = document.querySelector('script[data-videolesson-src="' + src + '"]');
            if (existing) {
                var check = window.setInterval(function () {
                    if (!ready || ready()) {
                        window.clearInterval(check);
                        resolve();
                    }
                }, 100);
                window.setTimeout(function () {
                    window.clearInterval(check);
                    if (!ready || ready()) {
                        resolve();
                    } else {
                        reject(new Error('Script load timeout'));
                    }
                }, 15000);
                return;
            }
            var script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.dataset.videolessonSrc = src;
            script.onload = function () {
                if (!ready || ready()) {
                    resolve();
                } else {
                    window.setTimeout(function () {
                        if (!ready || ready()) {
                            resolve();
                        } else {
                            reject(new Error('Provider unavailable'));
                        }
                    }, 250);
                }
            };
            script.onerror = reject;
            document.head.appendChild(script);
        });
    };

    var makeSessionKey = function () {
        var values = new Uint32Array(4);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(values);
            return Array.prototype.map.call(values, function (value) {
                return value.toString(16);
            }).join('');
        }
        return String(Date.now()) + String(Math.floor(Math.random() * 1000000000));
    };

    var stateName = function (value) {
        if (value === 'playing' || value === 'ended') {
            return value;
        }
        return 'paused';
    };

    var createHtml5Adapter = function () {
        var video = document.getElementById('videolesson-html5');
        if (!video) {
            return Promise.reject(new Error('HTML5 player not found'));
        }
        var source = video.getAttribute('src') || '';
        if (/\.m3u8(?:$|\?)/i.test(source) && !video.canPlayType('application/vnd.apple.mpegurl')) {
            return loadScript('https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js', function () {
                return typeof window.Hls !== 'undefined';
            }).then(function () {
                if (window.Hls.isSupported()) {
                    var hls = new window.Hls();
                    hls.loadSource(source);
                    hls.attachMedia(video);
                }
                return createHtml5AdapterReady(video);
            });
        }
        return Promise.resolve(createHtml5AdapterReady(video));
    };

    var createHtml5AdapterReady = function (video) {
        if (config.maxplaybackrate > 0) {
            video.addEventListener('ratechange', function () {
                if (video.playbackRate > config.maxplaybackrate) {
                    video.playbackRate = config.maxplaybackrate;
                }
            });
        }
        return {
            snapshot: function () {
                return Promise.resolve({
                    time: Number(video.currentTime) || 0,
                    duration: Number(video.duration) || 0,
                    rate: Number(video.playbackRate) || 1,
                    state: video.ended ? 'ended' : (!video.paused ? 'playing' : 'paused')
                });
            },
            seek: function (seconds) {
                video.currentTime = Math.max(0, Number(seconds) || 0);
                return Promise.resolve();
            },
            onImmediateUpdate: function (callback) {
                video.addEventListener('pause', callback);
                video.addEventListener('ended', callback);
                video.addEventListener('seeked', callback);
            }
        };
    };

    var createYoutubeAdapter = function () {
        return loadScript('https://www.youtube.com/iframe_api', function () {
            return window.YT && window.YT.Player;
        }).then(function () {
            return new Promise(function (resolve) {
                var player = new window.YT.Player('videolesson-youtube', {
                    videoId: config.videoid,
                    playerVars: {rel: 0, modestbranding: 1},
                    events: {
                        onReady: function () {
                            resolve({
                                snapshot: function () {
                                    var rawstate = player.getPlayerState();
                                    var state = rawstate === window.YT.PlayerState.PLAYING ? 'playing' :
                                        (rawstate === window.YT.PlayerState.ENDED ? 'ended' : 'paused');
                                    var rate = Number(player.getPlaybackRate()) || 1;
                                    if (config.maxplaybackrate > 0 && rate > config.maxplaybackrate) {
                                        player.setPlaybackRate(config.maxplaybackrate);
                                        rate = config.maxplaybackrate;
                                    }
                                    return Promise.resolve({
                                        time: Number(player.getCurrentTime()) || 0,
                                        duration: Number(player.getDuration()) || 0,
                                        rate: rate,
                                        state: state
                                    });
                                },
                                seek: function (seconds) {
                                    player.seekTo(Math.max(0, Number(seconds) || 0), true);
                                    return Promise.resolve();
                                },
                                onImmediateUpdate: function () {
                                }
                            });
                        }
                    }
                });
            });
        });
    };

    var createVimeoAdapter = function () {
        return loadScript('https://player.vimeo.com/api/player.js', function () {
            return window.Vimeo && window.Vimeo.Player;
        }).then(function () {
            var container = document.getElementById('videolesson-vimeo');
            var player = new window.Vimeo.Player(container, {id: config.videoid, responsive: true});
            var state = 'paused';
            var immediate = null;
            player.on('play', function () {
                state = 'playing';
            });
            player.on('pause', function () {
                state = 'paused';
                if (immediate) {
                    immediate();
                }
            });
            player.on('ended', function () {
                state = 'ended';
                if (immediate) {
                    immediate();
                }
            });
            return player.ready().then(function () {
                return {
                    snapshot: function () {
                        return Promise.all([
                            player.getCurrentTime(),
                            player.getDuration(),
                            player.getPlaybackRate()
                        ]).then(function (values) {
                            var rate = Number(values[2]) || 1;
                            if (config.maxplaybackrate > 0 && rate > config.maxplaybackrate) {
                                player.setPlaybackRate(config.maxplaybackrate).catch(function () {
                                });
                                rate = config.maxplaybackrate;
                            }
                            return {
                                time: Number(values[0]) || 0,
                                duration: Number(values[1]) || 0,
                                rate: rate,
                                state: state
                            };
                        });
                    },
                    seek: function (seconds) {
                        return player.setCurrentTime(Math.max(0, Number(seconds) || 0)).then(function () {
                        });
                    },
                    onImmediateUpdate: function (callback) {
                        immediate = callback;
                    }
                };
            });
        });
    };

    var createAdapter = function () {
        if (config.source === 'youtube') {
            return createYoutubeAdapter();
        }
        if (config.source === 'vimeo') {
            return createVimeoAdapter();
        }
        return createHtml5Adapter();
    };

    var updateCurrentChapter = function (seconds) {
        if (!config.chapters || !config.chapters.length) {
            return;
        }
        var current = config.chapters[0];
        config.chapters.forEach(function (chapter) {
            if (seconds + 0.01 >= Number(chapter.start)) {
                current = chapter;
            }
        });
        document.querySelectorAll('.videolesson-chapter-link').forEach(function (button) {
            button.classList.toggle('active', Number(button.dataset.chapterId) === Number(current.id));
        });
        document.querySelectorAll('[data-chapter-panel]').forEach(function (panel) {
            panel.classList.toggle('d-none', Number(panel.dataset.chapterPanel) !== Number(current.id));
        });
    };

    var setChapterStatus = function (chapter) {
        var status = M.util.get_string('notstarted', 'videolesson');
        if (chapter.completed) {
            status = M.util.get_string('completed', 'videolesson');
        } else if (chapter.inprogress) {
            status = M.util.get_string('inprogress', 'videolesson');
        }
        var nodes = document.querySelectorAll('[data-chapter-status="' + chapter.id + '"], [data-status-for="' + chapter.id + '"]');
        nodes.forEach(function (node) {
            node.textContent = status;
        });
        var side = document.querySelector('[data-sidebar-percent="' + chapter.id + '"]');
        if (side) {
            side.textContent = String(chapter.percentrounded);
        }
        var label = document.querySelector('[data-percent-label="' + chapter.id + '"]');
        if (label) {
            label.textContent = String(chapter.percent) + '%';
        }
        var bar = document.querySelector('[data-percent-bar="' + chapter.id + '"]');
        if (bar) {
            bar.style.width = String(chapter.percentrounded) + '%';
        }
    };

    var updateLocks = function (unlockedmax) {
        lastUnlockedMax = Number(unlockedmax);
        config.chapters.forEach(function (chapter) {
            var locked = lastUnlockedMax >= 0 && Number(chapter.start) > lastUnlockedMax + 0.1;
            chapter.locked = locked;
            var button = document.querySelector('.videolesson-chapter-link[data-chapter-id="' + chapter.id + '"]');
            if (button) {
                button.disabled = locked;
                if (locked) {
                    var status = button.querySelector('[data-chapter-status="' + chapter.id + '"]');
                    if (status) {
                        status.textContent = M.util.get_string('locked', 'videolesson');
                    }
                }
            }
        });
    };

    var applyState = function (state) {
        var overall = document.getElementById('videolesson-overall-label');
        var bar = document.getElementById('videolesson-overall-bar');
        if (overall) {
            overall.textContent = String(state.percent) + '%';
        }
        if (bar) {
            bar.style.width = String(state.percentrounded) + '%';
        }
        if (state.completed) {
            var completeMessage = document.getElementById('videolesson-complete-message');
            if (completeMessage) {
                completeMessage.classList.remove('d-none');
            }
        }
        (state.chapters || []).forEach(setChapterStatus);
        updateLocks(state.unlockedmax);
    };

    var enforceServerPosition = function (snapshot, state) {
        var accepted = Number(state.seekto);
        if (Math.abs(snapshot.time - accepted) > 2.0) {
            return adapter.seek(accepted).then(function () {
                updateCurrentChapter(accepted);
            });
        }
        return Promise.resolve();
    };

    var sendHeartbeat = function (force) {
        if (!adapter || busy) {
            return Promise.resolve();
        }
        busy = true;
        return adapter.snapshot().then(function (snapshot) {
            snapshot.state = stateName(snapshot.state);
            updateCurrentChapter(snapshot.time);
            if (lastUnlockedMax >= 0 && snapshot.time > lastUnlockedMax + 2) {
                return adapter.seek(lastUnlockedMax).then(function () {
                    snapshot.time = lastUnlockedMax;
                    snapshot.state = 'paused';
                    return snapshot;
                });
            }
            return snapshot;
        }).then(function (snapshot) {
            var start = previous ? Number(previous.time) : Number(snapshot.time);
            var end = Number(snapshot.time);
            if (!previous || previous.state !== 'playing' || end < start) {
                start = end;
            }
            sequence++;
            var request = Ajax.call([{
                methodname: 'mod_videolesson_update_progress',
                args: {
                    cmid: config.cmid,
                    sessionkey: sessionkey,
                    sequence: sequence,
                    duration: Math.max(0, Number(snapshot.duration) || 0),
                    currentposition: Math.max(0, Number(snapshot.time) || 0),
                    segmentstart: Math.max(0, start),
                    segmentend: Math.max(0, end),
                    playbackrate: Math.max(0.25, Number(snapshot.rate) || 1),
                    clienttime: Math.floor(Date.now() / 1000),
                    playerstate: snapshot.state
                }
            }])[0];
            return request.then(function (state) {
                previous = snapshot;
                applyState(state);
                return enforceServerPosition(snapshot, state);
            });
        }).catch(function (error) {
            if (force) {
                Notification.exception(error);
            }
        }).finally(function () {
            busy = false;
        });
    };

    var bindChapterNavigation = function () {
        document.querySelectorAll('.videolesson-chapter-link').forEach(function (button) {
            button.addEventListener('click', function () {
                if (button.disabled) {
                    Notification.addNotification({
                        message: M.util.get_string('seekblocked', 'videolesson'),
                        type: 'warning'
                    });
                    return;
                }
                var start = Number(button.dataset.start) || 0;
                adapter.seek(start).then(function () {
                    previous = null;
                    updateCurrentChapter(start);
                    return sendHeartbeat(false);
                });
            });
        });
    };

    var bindContentCompletion = function () {
        document.querySelectorAll('.videolesson-complete-item').forEach(function (button) {
            button.addEventListener('click', function () {
                var itemid = Number(button.dataset.itemId);
                var response = '';
                if (button.dataset.question === '1') {
                    var textarea = document.querySelector('[data-response-for="' + itemid + '"]');
                    response = textarea ? textarea.value : '';
                }
                button.disabled = true;
                Ajax.call([{
                    methodname: 'mod_videolesson_complete_item',
                    args: {cmid: config.cmid, itemid: itemid, response: response}
                }])[0].then(function (state) {
                    var badge = document.querySelector('[data-item-completed="' + itemid + '"]');
                    if (badge) {
                        badge.classList.remove('d-none');
                    }
                    button.classList.add('d-none');
                    applyState(state);
                    Notification.addNotification({
                        message: M.util.get_string('itemcomplete', 'videolesson'),
                        type: 'success'
                    });
                }).catch(function (error) {
                    button.disabled = false;
                    Notification.exception(error);
                });
            });
        });
    };

    var startTracking = function () {
        adapter.snapshot().then(function (snapshot) {
            lastUnlockedMax = Number(config.unlockedmax);
            var resume = config.resumeplayback ? Number(config.lastposition) : 0;
            if (lastUnlockedMax >= 0) {
                resume = Math.min(resume, lastUnlockedMax);
            }
            if (resume > 1) {
                return adapter.seek(resume).then(function () {
                    return snapshot;
                });
            }
            return snapshot;
        }).then(function (snapshot) {
            previous = {
                time: config.resumeplayback ? Math.max(0, Number(config.lastposition) || 0) : 0,
                state: 'paused'
            };
            updateCurrentChapter(previous.time);
            updateLocks(config.unlockedmax);
            bindChapterNavigation();
            bindContentCompletion();
            adapter.onImmediateUpdate(function () {
                sendHeartbeat(false);
            });
            timer = window.setInterval(function () {
                sendHeartbeat(false);
            }, 5000);
            sendHeartbeat(false);
        }).catch(function (error) {
            Notification.exception(error);
        });
    };

    var init = function () {
        root = document.getElementById('mod-videolesson-root');
        var configNode = document.getElementById('videolesson-config');
        if (!root || !configNode) {
            return;
        }
        try {
            config = JSON.parse(configNode.textContent || '{}');
        } catch (error) {
            Notification.exception(error);
            return;
        }
        sessionkey = makeSessionKey().replace(/[^A-Za-z0-9_-]/g, '').substring(0, 64);
        createAdapter().then(function (created) {
            adapter = created;
            startTracking();
        }).catch(function (error) {
            Notification.exception(error);
        });
        window.addEventListener('beforeunload', function () {
            if (timer) {
                window.clearInterval(timer);
            }
        });
    };

    return {init: init};
});
