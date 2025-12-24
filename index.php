<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kalle Anka-Bingo</title>
    <link href="css/style.css" rel="stylesheet" type="text/css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Mountains+of+Christmas:wght@400;700&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <div id="container">
        <div id="header">
            <h1>Kalle Anka-Bingo</h1>
        </div>
        
        <div id="app">
            <!-- Views will be injected here -->
        </div>

        <div id="footer">

            <button id="logoutBtn" style="display:none;">Logga ut</button>
        </div>
        
        <div id="winner-overlay" onclick="$(this).hide()">
            <div class="snow"></div>
            <div class="bingo-text">BINGO!</div>
        </div>
    </div>

    <script>
        const API_URL = 'api.php';
        
        const state = {
            user: null,
            items: [],
            activeGame: null,
            squares: []
        };

const app = $('#app');
        const logoutBtn = $('#logoutBtn');
        const clickSnd = new Audio("audio/click.mp3");
        const winSnd = new Audio("audio/win.mp3");

        // Views
        const Views = {
            Login: () => `
                <div class="view-login">
                    <h2>Välkommen</h2>
                    <p>Ange ett användarnamn för att börja spela.</p>
                    <input type="text" id="usernameInput" placeholder="Användarnamn" />
                    <button onclick="Handlers.login()">Börja Spela</button>
                    ${state.error ? `<p class="error">${state.error}</p>` : ''}
                </div>
            `,
            Dashboard: () => `
                <div class="view-dashboard">
                    <h2>Hej ${state.user.username}!</h2>
                    ${state.activeGame ? 
                        `<button class="big-btn" onclick="Handlers.loadGame()">Fortsätt Spela</button>` : 
                        `<div class="info-box">Du har ingen aktiv bricka.</div>`
                    }
                    <button class="big-btn secondary" onclick="Handlers.showCreateGame()">Skapa Ny Bricka</button>
                </div>
            `,
            CreateGame: () => {
                let html = `<div class="view-create">
                    <h2>Välj 25 rutor</h2>
                    <p>Valda: <span id="selectedCount">0</span>/25</p>
                    <button onclick="Handlers.randomizeSelection()" class="secondary">Slumpa 25</button>
                    <button id="createGameBtn" onclick="Handlers.createGame()" disabled>Starta Spel</button>
                    <div class="item-list">`;
                
                state.items.forEach(item => {
                    html += `
                        <div class="item-checbkox">
                            <label>
                                <input type="checkbox" value="${item.id}" onchange="Handlers.updateSelection(this)">
                                ${item.content}
                            </label>
                        </div>
                    `;
                });

                html += `</div></div>`;
                return html;
            },
            Game: () => {
                let html = `<div class="view-game">
                    <div id="board">`;
                
                state.squares.forEach(sq => {
                    const isChecked = sq.is_checked == 1 ? 'selected' : '';
                    html += `
                        <div class="square ${isChecked}" 
                             id="sq${sq.id}" 
                             onclick="Handlers.toggleSquare(${sq.id})">
                             <div class="text">${sq.content}</div>
                        </div>
                    `;
                });

                html += `</div></div>`;
                return html;
            }
        };

        // Handlers
        const Handlers = {
            init: async () => {
                const res = await fetch(`${API_URL}?action=check_auth`);
                const data = await res.json();
                if (data.authenticated) {
                    state.user = { username: data.username };
                    logoutBtn.show();
                    await Handlers.loadDashboardData();
                } else {
                    Handlers.render('Login');
                }
            },

            login: async () => {
                const username = $('#usernameInput').val();
                if (!username) return;

                const res = await fetch(`${API_URL}?action=login`, {
                    method: 'POST',
                    body: JSON.stringify({ username })
                });
                const data = await res.json();
                
                if (data.success) {
                    state.user = { username };
                    logoutBtn.show();
                    await Handlers.loadDashboardData();
                } else {
                    state.error = data.error;
                    Handlers.render('Login');
                }
            },

            logout: async () => {
                await fetch(`${API_URL}?action=logout`);
                state.user = null;
                state.activeGame = null;
                logoutBtn.hide();
                Handlers.render('Login');
            },

            loadDashboardData: async () => {
                const res = await fetch(`${API_URL}?action=get_active_game`);
                const data = await res.json();
                state.activeGame = data.hasGame ? data.game : null;
                state.squares = data.squares || [];
                Handlers.render('Dashboard');
            },

            showCreateGame: async () => {
                const res = await fetch(`${API_URL}?action=get_items`);
                state.items = await res.json();
                Handlers.render('CreateGame');
            },

            randomizeSelection: () => {
                // Clear all
                $('input[type="checkbox"]').prop('checked', false);
                
                // Shuffle items (simple Fisher-Yates or just pick random indices)
                const checkboxes = $('input[type="checkbox"]').toArray();
                
                // Fisher-Yates shuffle
                for (let i = checkboxes.length - 1; i > 0; i--) {
                    const j = Math.floor(Math.random() * (i + 1));
                    [checkboxes[i], checkboxes[j]] = [checkboxes[j], checkboxes[i]];
                }

                // Select first 25
                checkboxes.slice(0, 25).forEach(cb => $(cb).prop('checked', true));
                
                Handlers.updateSelection();
            },

            updateSelection: (checkbox) => {
                const count = $('input[type="checkbox"]:checked').length;
                $('#selectedCount').text(count);
                $('#createGameBtn').prop('disabled', count !== 25);
            },

            createGame: async () => {
                const selected = $('input[type="checkbox"]:checked').map(function() {
                    return $(this).val();
                }).get();

                if (selected.length !== 25) return;

                const res = await fetch(`${API_URL}?action=create_game`, {
                    method: 'POST',
                    body: JSON.stringify({ itemIds: selected })
                });
                const data = await res.json();
                if (data.success) {
                    await Handlers.loadDashboardData(); // Refetch to get new game state
                    Handlers.loadGame();
                }
            },

            loadGame: () => {
                Handlers.render('Game');
                Handlers.checkWin();
            },

            toggleSquare: async (id) => {
                const sq = state.squares.find(s => s.id == id);
                // Optimistic update
                const el = $(`#sq${id}`);
                const wasChecked = el.hasClass('selected');
                el.toggleClass('selected');
                
                if (sq) sq.is_checked = !wasChecked;
                
                // Play click sound
                clickSnd.currentTime = 0;
                clickSnd.play().catch(e => console.log('Audio error:', e));

                Handlers.checkWin();

                const res = await fetch(`${API_URL}?action=toggle_square`, {
                    method: 'POST',
                    body: JSON.stringify({ squareId: id })
                });
                const data = await res.json();
                if (!data.success) {
                    // Revert if failed
                    el.toggleClass('selected');
                    if (sq) sq.is_checked = wasChecked;
                }
            },
            
            render: (viewName) => {
                app.html(Views[viewName]());
            },

            checkWin: () => {
                // Map squares to position array 0-24
                const grid = new Array(25).fill(0);
                state.squares.forEach(sq => {
                    if (sq.is_checked) grid[sq.position] = 1;
                });

                const wins = [
                    // Rows
                    [0,1,2,3,4], [5,6,7,8,9], [10,11,12,13,14], [15,16,17,18,19], [20,21,22,23,24],
                    // Cols
                    [0,5,10,15,20], [1,6,11,16,21], [2,7,12,17,22], [3,8,13,18,23], [4,9,14,19,24],
                    // Diags
                    [0,6,12,18,24], [4,8,12,16,20]
                ];

                let isWin = false;
                for (let combo of wins) {
                    if (combo.every(pos => grid[pos] === 1)) {
                        isWin = true;
                        break;
                    }
                }

                if (isWin) {
                    if (!$('#header').hasClass('has-won')) {
                        $('#header').addClass('has-won');
                        
                        // Play win sound
                        winSnd.currentTime = 0;
                        const playPromise = winSnd.play();
                        if (playPromise !== undefined) {
                            playPromise.catch(e => {
                                console.error('Win Sound error:', e);
                            });
                        }

                        // Show overlay
                        $('#winner-overlay').css('display', 'flex');
                        Handlers.startSnow();
                    }
                } else {
                    $('#header').removeClass('has-won');
                    $('#header h1').html('Kalle Anka-Bingo');
                    $('#winner-overlay').hide();
                }
            },

            startSnow: () => {
                const snowContainer = $('.snow');
                snowContainer.empty();
                for (let i = 0; i < 50; i++) {
                    const flake = $('<div class="snowflake"></div>');
                    const x = Math.random() * 100;
                    const delay = Math.random() * 5;
                    const duration = 5 + Math.random() * 5;
                    
                    flake.css({
                        left: x + '%',
                        animationDelay: delay + 's',
                        animationDuration: duration + 's',
                        opacity: Math.random()
                    });
                    
                    snowContainer.append(flake);
                }
            }
        };

        // Listeners
        logoutBtn.click(Handlers.logout);

        // Start
        $(document).ready(Handlers.init);

    </script>
</body>
</html>
