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
 * @module     mod_lessonvideo/player
 * @copyright  2026 Eduardo Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';

let root;
let config;
let adapter;
let sequence = 0;
let sessionkey = '';
let previous = null;
let busy = false;
let timer = null;
let lastUnlockedMax = -1;

let loadScript = function (src, ready) {
    return new Promise(function (resolve, reject) {
        if (ready && ready()) {
            resolve();
            return;
        }
        let existing = document.querySelector('script[data-lessonvideo-src="' + src + '"]');
        if (existing) {
            let check = window.setInterval(function () {
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
        let script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.dataset.lessonvideoSrc = src;
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

let makeSessionKey = function () {
    let values = new Uint32Array(4);
    if (window.crypto && window.crypto.getRandomValues) {
        window.crypto.getRandomValues(values);
        return Array.prototype.map.call(values, function (value) {
            return value.toString(16);
        }).join('');
    }
    return String(Date.now()) + String(Math.floor(Math.random() * 1000000000));
};

let stateName = function (value) {
    if (value === 'playing' || value === 'ended') {
        return value;
    }
    return 'paused';
};

let createHtml5Adapter = function () {
    let video = document.getElementById('lessonvideo-html5');
    if (!video) {
        return Promise.reject(new Error('HTML5 player not found'));
    }
    let source = video.getAttribute('src') || '';
    if (/\.m3u8(?:$|\?)/i.test(source) && !video.canPlayType('application/vnd.apple.mpegurl')) {
        return loadScript(config.hlsurl, function () {
            return typeof window.Hls !== 'undefined';
        }).then(function () {
            if (window.Hls.isSupported()) {
                let hls = new window.Hls();
                hls.loadSource(source);
                hls.attachMedia(video);
            }
            return createHtml5AdapterReady(video);
        });
    }
    return Promise.resolve(createHtml5AdapterReady(video));
};

let createHtml5AdapterReady = function (video) {
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

let createYoutubeAdapter = function () {
    return loadScript('https://www.youtube.com/iframe_api', function () {
        return window.YT && window.YT.Player;
    }).then(function () {
        return new Promise(function (resolve) {
            let player = new window.YT.Player('lessonvideo-youtube', {
                videoId: config.videoid,
                playerVars: {rel: 0, modestbranding: 1},
                events: {
                    onReady: function () {
                        resolve({
                            snapshot: function () {
                                let rawstate = player.getPlayerState();
                                let state = rawstate === window.YT.PlayerState.PLAYING ? 'playing' :
                                    (rawstate === window.YT.PlayerState.ENDED ? 'ended' : 'paused');
                                let rate = Number(player.getPlaybackRate()) || 1;
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

let createVimeoAdapter = function () {
    return loadScript('https://player.vimeo.com/api/player.js', function () {
        return window.Vimeo && window.Vimeo.Player;
    }).then(function () {
        let container = document.getElementById('lessonvideo-vimeo');
        let player = new window.Vimeo.Player(container, {id: config.videoid, responsive: true});
        let state = 'paused';
        let immediate = null;
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
                        let rate = Number(values[2]) || 1;
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

let createAdapter = function () {
    if (config.source === 'youtube') {
        return createYoutubeAdapter();
    }
    if (config.source === 'vimeo') {
        return createVimeoAdapter();
    }
    return createHtml5Adapter();
};

let updateCurrentChapter = function (seconds) {
    if (!config.chapters || !config.chapters.length) {
        return;
    }
    let current = config.chapters[0];
    config.chapters.forEach(function (chapter) {
        if (seconds + 0.01 >= Number(chapter.start)) {
            current = chapter;
        }
    });
    document.querySelectorAll('.lessonvideo-chapter-link').forEach(function (button) {
        button.classList.toggle('active', Number(button.dataset.chapterId) === Number(current.id));
    });
    document.querySelectorAll('[data-chapter-panel]').forEach(function (panel) {
        panel.classList.toggle('d-none', Number(panel.dataset.chapterPanel) !== Number(current.id));
    });
};

let setChapterStatus = function (chapter) {
    let status = M.util.get_string('notstarted', 'lessonvideo');
    if (chapter.completed) {
        status = M.util.get_string('completed', 'lessonvideo');
    } else if (chapter.inprogress) {
        status = M.util.get_string('inprogress', 'lessonvideo');
    }
    let nodes = document.querySelectorAll('[data-chapter-status="' + chapter.id + '"], [data-status-for="' + chapter.id + '"]');
    nodes.forEach(function (node) {
        node.textContent = status;
    });
    let side = document.querySelector('[data-sidebar-percent="' + chapter.id + '"]');
    if (side) {
        side.textContent = String(chapter.percentrounded);
    }
    let label = document.querySelector('[data-percent-label="' + chapter.id + '"]');
    if (label) {
        label.textContent = String(chapter.percent) + '%';
    }
    let bar = document.querySelector('[data-percent-bar="' + chapter.id + '"]');
    if (bar) {
        bar.style.width = String(chapter.percentrounded) + '%';
    }
};

let updateLocks = function (unlockedmax) {
    lastUnlockedMax = Number(unlockedmax);
    config.chapters.forEach(function (chapter) {
        let locked = lastUnlockedMax >= 0 && Number(chapter.start) > lastUnlockedMax + 0.1;
        chapter.locked = locked;
        let button = document.querySelector('.lessonvideo-chapter-link[data-chapter-id="' + chapter.id + '"]');
        if (button) {
            button.disabled = locked;
            if (locked) {
                let status = button.querySelector('[data-chapter-status="' + chapter.id + '"]');
                if (status) {
                    status.textContent = M.util.get_string('locked', 'lessonvideo');
                }
            }
        }
    });
};

let applyState = function (state) {
    let overall = document.getElementById('lessonvideo-overall-label');
    let bar = document.getElementById('lessonvideo-overall-bar');
    if (overall) {
        overall.textContent = String(state.percent) + '%';
    }
    if (bar) {
        bar.style.width = String(state.percentrounded) + '%';
    }
    if (state.completed) {
        let completeMessage = document.getElementById('lessonvideo-complete-message');
        if (completeMessage) {
            completeMessage.classList.remove('d-none');
        }
    }
    (state.chapters || []).forEach(setChapterStatus);
    updateLocks(state.unlockedmax);
};

let enforceServerPosition = function (snapshot, state) {
    let accepted = Number(state.seekto);
    if (Math.abs(snapshot.time - accepted) > 2.0) {
        return adapter.seek(accepted).then(function () {
            updateCurrentChapter(accepted);
        });
    }
    return Promise.resolve();
};

let sendHeartbeat = function (force) {
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
        let start = previous ? Number(previous.time) : Number(snapshot.time);
        let end = Number(snapshot.time);
        if (!previous || previous.state !== 'playing' || end < start) {
            start = end;
        }
        sequence++;
        let request = Ajax.call([{
            methodname: 'mod_lessonvideo_update_progress',
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

let bindChapterNavigation = function () {
    document.querySelectorAll('.lessonvideo-chapter-link').forEach(function (button) {
        button.addEventListener('click', function () {
            if (button.disabled) {
                Notification.addNotification({
                    message: M.util.get_string('seekblocked', 'lessonvideo'),
                    type: 'warning'
                });
                return;
            }
            let start = Number(button.dataset.start) || 0;
            adapter.seek(start).then(function () {
                previous = null;
                updateCurrentChapter(start);
                return sendHeartbeat(false);
            });
        });
    });
};

let bindContentCompletion = function () {
    document.querySelectorAll('.lessonvideo-complete-item').forEach(function (button) {
        button.addEventListener('click', function () {
            let itemid = Number(button.dataset.itemId);
            let response = '';
            if (button.dataset.question === '1') {
                let textarea = document.querySelector('[data-response-for="' + itemid + '"]');
                response = textarea ? textarea.value : '';
            }
            button.disabled = true;
            Ajax.call([{
                methodname: 'mod_lessonvideo_complete_item',
                args: {cmid: config.cmid, itemid: itemid, response: response}
            }])[0].then(function (state) {
                let badge = document.querySelector('[data-item-completed="' + itemid + '"]');
                if (badge) {
                    badge.classList.remove('d-none');
                }
                button.classList.add('d-none');
                applyState(state);
                Notification.addNotification({
                    message: M.util.get_string('itemcomplete', 'lessonvideo'),
                    type: 'success'
                });
            }).catch(function (error) {
                button.disabled = false;
                Notification.exception(error);
            });
        });
    });
};

let startTracking = function () {
    adapter.snapshot().then(function (snapshot) {
        lastUnlockedMax = Number(config.unlockedmax);
        let resume = config.resumeplayback ? Number(config.lastposition) : 0;
        if (lastUnlockedMax >= 0) {
            resume = Math.min(resume, lastUnlockedMax);
        }
        if (resume > 1) {
            return adapter.seek(resume).then(function () {
                return snapshot;
            });
        }
        return snapshot;
    }).then(function () {
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

export const init = function () {
    root = document.getElementById('mod-lessonvideo-root');
    let configNode = document.getElementById('lessonvideo-config');
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


