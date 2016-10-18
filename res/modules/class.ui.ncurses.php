<?php


class AppUI_NCurses extends AppUI_common {

	protected $windows = array();
	protected $cur_x;
	protected $cur_y;
	protected $map = array();
	protected $colwid = array(); // ширины столбцов

	public function __destruct() {
		$this->closeClean();
	}

	// только ради требований базового класса, обращений к модулю извне не предполагается
	public function process(ClientRequest $req) {
	}

	public function init() {
		if (!function_exists('ncurses_init')) {
			throw new Exception('NCurses UI not available. check ncurses PHP extension installed');
		}
		// конфиг раскладки по колонкам
		$this->colwid = array(
			0 => 24, // channel (variable!)
			8, // Buffer
			9,	// State
			6,	// peers
			15,	// up/down bytes
			25,	// Client list
			12,	// download/upload speed
		);
		$this->initWindows();
	}

	// вызывать каждый цикл. выводим массив трансляций
	public function draw() {
		$addinfo = $this->getApp()->getUIAdditionalInfo();
		$streams = $this->makePlainStreamsArray($this->getStreams());

		ncurses_werase ($this->windows['stat']);
		ncurses_wborder($this->windows['stat'], 0,0, 0,0, 0,0, 0,0);

		$this->listen4resize();

		if (isset($addinfo['title'])) {
			$this->output('stat', 0, 2, $addinfo['title']);
		}
		if (isset($addinfo['port'])) {
			$this->output('stat', 0, 25, sprintf(' Port %d ', $addinfo['port']));
		}
		if (isset($addinfo['ram'])) {
			$this->output('stat', 0, 38, sprintf(' RAM: %s MB ', $addinfo['ram']));
		}
		if (isset($addinfo['uptime'])) {
			$this->output('stat', 0, 54, sprintf(' Uptime %s ', $addinfo['uptime']));
		}

		$i = 1;
		$map = $this->map;

		// выводим все коннекты и трансляции
		$this->outputCol('stat', $i, 0, "");
		$this->outputCol('stat', $i, 1, "Buffer");
		$this->outputCol('stat', $i, 2, "State");
		$this->outputCol('stat', $i, 3, "Peers");
		$this->outputCol('stat', $i, 4, "Up  (MB) Down");
		$this->outputCol('stat', $i, 5, "Client");
		$this->outputCol('stat', $i, 6, "DL (kbps) UL");
		$i++;

		foreach ($streams as $row) {
			$i++;
			foreach ($row as $colidx => $str) {
				$this->outputCol('stat', $i, $colidx, $str);
			}
		}

		// состояние инета
		// ascii table http://www.linuxivr.com/c/week6/ascii_window.jpg
		#iconv('cp866', 'utf8', chr(0xb4)),
		#iconv('cp866', 'utf8', chr(0xc3))
		$str = array(
			0 => ($wwwok = !empty($addinfo['wwwok'])) ? self::CLR_GREEN : self::CLR_ERROR,
			1 => sprintf(' %s ', $wwwok ? 'online' : 'offline')
		);
		$this->outputCol('stat', 0, 6, $str);

		ncurses_wrefresh($this->windows['stat']);

		// перерисуем окно лога, при ресайзе оно не обновляется
		// TODO один хрен косяк
		ncurses_wborder($this->windows['log'], 0,0, 0,0, 0,0, 0,0);
		ncurses_wrefresh($this->windows['log']);
	}

	public function log($msg, $color = self::CLR_DEFAULT) {
		ncurses_getmaxyx ($this->windows['log'], $y, $x);
		ncurses_getyx ($this->windows['log'], $cy, $cx); // cursor xy
		if ($cy > $y - 3) {
			ncurses_werase ($this->windows['log']);
			ncurses_wborder($this->windows['log'], 0,0, 0,0, 0,0, 0,0);
			$cy = 0;
		}
		$msg = mb_substr($msg, 0, $x - 2);

		$color and ncurses_wcolor_set($this->windows['log'], $color);
		ncurses_mvwaddstr ($this->windows['log'], $cy + 1, 1, $msg);
		ncurses_clrtoeol ();
		$color and ncurses_wcolor_set($this->windows['log'], self::CLR_DEFAULT);

		// никак скроллить не выходит
		#ncurses_insdelln (1);
		#ncurses_scrl (-2); // вообще 0 реакции
		#ncurses_insertln ();
		ncurses_wrefresh($this->windows['log']);
	}

	// закрывает сессию ncurses
	protected function closeClean() {
		ncurses_end(); // выходим из режима ncurses, чистим экран
	}

	private function initWindows() {
		// начинаем с инициализации библиотеки
		$ncurse = ncurses_init();
		// используем весь экран
		$this->windows['main'] = ncurses_newwin ( 0, 0, 0, 0); 
		// рисуем рамку вокруг окна
		ncurses_border(0,0, 0,0, 0,0, 0,0);
		ncurses_getmaxyx ($this->windows['main'], $y, $x);
		// save current main window size
		$this->cur_x = $x;
		$this->cur_y = $y;

		// создаём второе окно для лога
		$rows = floor($y / 2); $cols = $x; $sy = $y - $rows; $sx = 0;
		$this->windows['log'] = ncurses_newwin($rows, $cols, $sy, $sx);

		// и окно для статистики (остальное пространство)
		$rows = $y - $rows; $cols = $x; $sy = 0; $sx = 0;
		$this->windows['stat'] = ncurses_newwin($rows, $cols, $sy, $sx);

		if (ncurses_has_colors()) {
			ncurses_start_color();
			// colors http://php.net/manual/en/ncurses.colorconsts.php
			ncurses_init_pair(self::CLR_ERROR, NCURSES_COLOR_RED, NCURSES_COLOR_BLACK);
			ncurses_init_pair(self::CLR_GREEN, NCURSES_COLOR_GREEN, NCURSES_COLOR_BLACK);
			ncurses_init_pair(self::CLR_YELLOW, NCURSES_COLOR_YELLOW, NCURSES_COLOR_BLACK);
			ncurses_init_pair(self::CLR_SPEC1, NCURSES_COLOR_RED, NCURSES_COLOR_WHITE);
			ncurses_init_pair(5, NCURSES_COLOR_MAGENTA, NCURSES_COLOR_BLACK);
			ncurses_init_pair(6, NCURSES_COLOR_CYAN, NCURSES_COLOR_BLACK);
			ncurses_init_pair(self::CLR_DEFAULT, NCURSES_COLOR_WHITE, NCURSES_COLOR_BLACK);
			$this->log('Init colors', self::CLR_GREEN);
		}

		// рамка для него
		ncurses_wborder($this->windows['log'], 0,0, 0,0, 0,0, 0,0);
		ncurses_wborder($this->windows['stat'], 0,0, 0,0, 0,0, 0,0);

		ncurses_nl ();
		ncurses_curs_set (0); // visibility

		ncurses_refresh(); // рисуем окна

		// обновляем маленькое окно для вывода строки
		ncurses_wrefresh($this->windows['log']);
	}

	protected function listen4resize() {
		ncurses_getmaxyx ($this->windows['main'], $y, $x);
		if ($x != $this->cur_x or $y != $this->cur_y) {
			// restart ncurses session, redraw all
			$this->closeClean();
			$this->initWindows();
		}

		// save current main window size
		$this->cur_x = $x;
		$this->cur_y = $y;

		$startoffset = 2; // небольшой отступ на отрисовку границ, чтобы на них не налезал контент
		// ширина первого столбца определяется как разность ширины окна и всех столбцов, кроме первого
		$colsum = array_sum($this->colwid) - $this->colwid[0];
		$this->colwid[0] = $this->cur_x - $colsum - ($startoffset * 2); // *2 ибо с 2 сторон

		// renew map
		$col = 0;
		$this->map = array(
			0 => $col += $startoffset, // channel
			$col += $this->colwid[0],	// Buffer, but 25 is Channel width!
			$col += $this->colwid[1],	// State
			$col += $this->colwid[2],	// up/down bytes
			$col += $this->colwid[3],	// peers
			$col += $this->colwid[4],	// Client list
			$col += $this->colwid[5],	// download/upload speed
		);
	}

	protected function outputTitle($title) {
		ncurses_attron(NCURSES_A_REVERSE);
		ncurses_mvaddstr(0, 1, $title);
		ncurses_attroff(NCURSES_A_REVERSE);
		ncurses_refresh(); // рисуем окна
	}

	protected function outputCol($wcode, $y, $col, $str) {
		$x = $this->map[$col];
		$maxwid = $this->colwid[$col];
		// -1 чтобы не сливалось со след.столбцом, но для последнего столбца неактуально
		if ($col < count($this->colwid) - 1) {
			$maxwid -= 1;
		}
		return $this->output($wcode, $y, $x, $str, $maxwid);
	}
	protected function output($wcode, $y, $x, $str, $maxwid = null) {
		$w = $this->windows[$wcode];
		$color = null;
		if (is_array($str)) {
			$color = $str[0];
			$str = $str[1];
		}
		if (!is_null($maxwid) and mb_strlen($str) > $maxwid) {
			$str = mb_substr($str, 0, $maxwid);
		}

		$color and ncurses_wcolor_set($w, $color);
		ncurses_mvwaddstr($w, $y, $x, $str);
		$color and ncurses_wcolor_set($w, self::CLR_DEFAULT);
	}
}

