/**
 * Modern ECharts Pie/Donut Chart Implementation
 * Inspired by https://ip.space.ua/info.php & EFA-NG Design
 */

var piePalette = [
  '#1f6cb0', // EFA Primary Blue
  '#10b981', // Emerald Green
  '#f59e0b', // Amber
  '#ef4444', // Red
  '#8b5cf6', // Purple
  '#06b6d4', // Cyan
  '#ec4899', // Pink
  '#3b82f6', // Blue
  '#14b8a6', // Teal
  '#f97316', // Orange
  '#6366f1', // Indigo
  '#84cc16', // Lime
  '#a855f7', // Violet
  '#d97706', // Ochre
  '#0284c7', // Sky Blue
  '#e11d48'  // Rose
];

function printPieGraph(chartId, settings) {
  var chartDom = document.getElementById(chartId);
  if (!chartDom) return;

  if (typeof echarts === 'undefined') {
    console.error('ECharts library not loaded');
    return;
  }

  // Dispose previous instance if re-rendering
  var existingChart = echarts.getInstanceByDom(chartDom);
  if (existingChart) {
    existingChart.dispose();
  }

  var myChart = echarts.init(chartDom);

  var chartData = [];
  var labels = settings.chartLabels || [];
  var numericData = settings.chartNumericData || [];
  var formattedData = settings.chartFormattedData || [];

  for (var i = 0; i < labels.length; i++) {
    var val = (typeof numericData[i] !== 'undefined') ? numericData[i] : 0;
    var formattedVal = (typeof formattedData[i] !== 'undefined') ? formattedData[i] : val;
    chartData.push({
      name: labels[i],
      value: val,
      formattedValue: formattedVal
    });
  }

  var option = {
    title: {
      text: settings.chartTitle || '',
      left: 'center',
      top: 6,
      textStyle: {
        fontSize: 15,
        fontWeight: 700,
        color: '#0f172a',
        fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif'
      }
    },
    tooltip: {
      trigger: 'item',
      backgroundColor: 'rgba(15, 23, 42, 0.92)',
      borderColor: '#334155',
      borderWidth: 1,
      padding: [8, 12],
      textStyle: {
        color: '#f8fafc',
        fontSize: 12
      },
      formatter: function(params) {
        var raw = params.data || {};
        var displayVal = raw.formattedValue || params.value;
        return '<div style="font-weight: 700; margin-bottom: 2px;">' + params.marker + ' ' + params.name + '</div>' +
               '<div>Count: <b>' + displayVal + '</b> (' + params.percent + '%)</div>';
      }
    },
    legend: {
      type: 'scroll',
      orient: 'vertical',
      right: 15,
      top: 'middle',
      itemGap: 8,
      itemWidth: 12,
      itemHeight: 12,
      itemStyle: {
        borderColor: '#94a3b8',
        borderWidth: 1,
        borderRadius: 2
      },
      textStyle: {
        color: '#475569',
        fontSize: 11
      },
      formatter: function(name) {
        return echarts.format.truncateText(name, 160, '11px sans-serif', '…');
      }
    },
    color: piePalette,
    series: [
      {
        name: settings.chartTitle || '',
        type: 'pie',
        radius: ['38%', '70%'],
        center: ['40%', '54%'],
        avoidLabelOverlap: true,
        padAngle: 3,
        itemStyle: {
          borderRadius: 6,
          borderColor: '#94a3b8',
          borderWidth: 1.5
        },
        label: {
          show: false,
          position: 'center'
        },
        emphasis: {
          label: {
            show: true,
            fontSize: 14,
            fontWeight: 'bold',
            color: '#0f172a',
            formatter: '{b}\n{d}%'
          },
          itemStyle: {
            borderColor: '#475569',
            borderWidth: 2,
            shadowBlur: 10,
            shadowOffsetX: 0,
            shadowColor: 'rgba(0, 0, 0, 0.15)'
          }
        },
        labelLine: {
          show: false
        },
        data: chartData
      }
    ]
  };

  myChart.setOption(option);

  // Auto-resize on window change
  window.addEventListener('resize', function() {
    myChart.resize();
  });
}
